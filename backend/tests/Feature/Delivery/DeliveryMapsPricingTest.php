<?php

namespace Tests\Feature\Delivery;

use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\DeliveryRoute;
use App\Data\Delivery\GeocodedDeliveryAddress;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Delivery\WhatsAppDeliveryLocationCapture;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryMapsPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_km_uses_meters_and_nearest_cent_without_calling_a_real_provider(): void
    {
        $setting = DeliverySetting::query()->create([
            'company_id' => $this->company()->id,
            'calculation_mode' => DeliveryPricingService::MODE_PER_KM,
            'price_per_km_cents' => 300,
        ]);

        $result = app(DeliveryPricingService::class)->calculate($setting, 3400);

        $this->assertSame(1020, $result['delivery_fee_cents']);
        $this->assertSame(0, $result['surcharge_cents']);
        $this->assertSame(3400, $result['snapshot']['distance_meters']);
    }

    public function test_distance_bands_have_unambiguous_inclusive_upper_limits(): void
    {
        $service = app(DeliveryPricingService::class);
        $setting = DeliverySetting::query()->create([
            'company_id' => $this->company()->id,
            'calculation_mode' => DeliveryPricingService::MODE_DISTANCE_BANDS,
            'price_per_km_cents' => 0,
            'provider_options' => ['distance_bands' => [
                ['up_to_meters' => 2000, 'fee_cents' => 500],
                ['up_to_meters' => 4000, 'fee_cents' => 800],
            ]],
        ]);

        $this->assertSame(500, $service->calculate($setting, 2000)['delivery_fee_cents']);
        $this->assertSame(800, $service->calculate($setting, 2001)['delivery_fee_cents']);
    }

    public function test_distance_band_settings_accept_an_absent_null_or_legacy_zero_per_km_rate(): void
    {
        [$company, $user] = $this->deliverySettingsActor();
        $basePayload = [
            'maps_provider' => 'fake',
            'pricing_mode' => DeliveryPricingService::MODE_DISTANCE_BANDS,
            'origin' => ['address' => 'Restaurante', 'latitude' => -16.31, 'longitude' => -48.94],
            'distance_bands' => [['up_to_meters' => 1000, 'fee_cents' => 1230]],
        ];

        foreach ([
            $basePayload,
            [...$basePayload, 'rate_per_km_cents' => null],
            [...$basePayload, 'rate_per_km_cents' => 0],
        ] as $payload) {
            $this->actingAs($user)
                ->patchJson('/api/app/delivery-settings', $payload)
                ->assertOk()
                ->assertJsonPath('data.calculation_mode', DeliveryPricingService::MODE_DISTANCE_BANDS)
                ->assertJsonPath('data.price_per_km_cents', 0)
                ->assertJsonPath('data.provider_options.distance_bands.0.up_to_meters', 1000)
                ->assertJsonPath('data.provider_options.distance_bands.0.fee_cents', 1230);
        }

        $setting = DeliverySetting::query()->where('company_id', $company->id)->sole();
        $this->assertSame(1230, app(DeliveryPricingService::class)->calculate($setting, 1000)['delivery_fee_cents']);
    }

    public function test_per_km_settings_require_a_positive_rate_and_do_not_require_distance_bands(): void
    {
        [, $user] = $this->deliverySettingsActor();
        $basePayload = [
            'maps_provider' => 'fake',
            'pricing_mode' => DeliveryPricingService::MODE_PER_KM,
            'origin' => ['address' => 'Restaurante', 'latitude' => -16.31, 'longitude' => -48.94],
        ];

        $this->actingAs($user)
            ->patchJson('/api/app/delivery-settings', [...$basePayload, 'rate_per_km_cents' => 245])
            ->assertOk()
            ->assertJsonPath('data.calculation_mode', DeliveryPricingService::MODE_PER_KM)
            ->assertJsonPath('data.price_per_km_cents', 245)
            ->assertJsonPath('data.provider_options.distance_bands', []);

        foreach ([$basePayload, [...$basePayload, 'rate_per_km_cents' => 0]] as $payload) {
            $this->actingAs($user)
                ->patchJson('/api/app/delivery-settings', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rate_per_km_cents');
        }
    }

    public function test_distance_band_settings_require_non_empty_valid_bands(): void
    {
        [, $user] = $this->deliverySettingsActor();
        $basePayload = [
            'maps_provider' => 'fake',
            'pricing_mode' => DeliveryPricingService::MODE_DISTANCE_BANDS,
            'origin' => ['address' => 'Restaurante', 'latitude' => -16.31, 'longitude' => -48.94],
        ];

        foreach ([$basePayload, [...$basePayload, 'distance_bands' => []]] as $payload) {
            $this->actingAs($user)
                ->patchJson('/api/app/delivery-settings', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('distance_bands');
        }

        $this->actingAs($user)
            ->patchJson('/api/app/delivery-settings', [
                ...$basePayload,
                'distance_bands' => [['up_to_meters' => 0, 'fee_cents' => -1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'distance_bands.0.up_to_meters',
                'distance_bands.0.fee_cents',
            ]);
    }

    public function test_routing_snapshots_the_calculation_and_updates_the_order_total_once(): void
    {
        $company = $this->company();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);
        $address = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'street' => 'Rua de teste',
            'latitude' => -23.55,
            'longitude' => -46.63,
        ]);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'delivery_address_id' => $address->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'ENT-TESTE-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'subtotal_cents' => 1500,
            'total_cents' => 1500,
            'amount_due_cents' => 1500,
        ]);
        DeliverySetting::query()->create([
            'company_id' => $company->id,
            'calculation_mode' => DeliveryPricingService::MODE_PER_KM,
            'price_per_km_cents' => 300,
            'provider_options' => ['origin' => ['latitude' => -23.54, 'longitude' => -46.62]],
        ]);
        $this->app->instance(DeliveryRouteProviderInterface::class, new class implements DeliveryRouteProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute
            {
                return new DeliveryRoute(3400, 720, 'fake', 'route-test', 'encoded');
            }
        });
        $this->app->instance(DeliveryGeocodingProviderInterface::class, new class implements DeliveryGeocodingProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): never
            {
                throw new \LogicException('Not used.');
            }
        });

        $quote = app(DeliveryRoutingService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame(1020, $quote->delivery_fee_cents);
        $this->assertSame(1020, $order->total_cents);
        $this->assertSame(1020, $order->amount_due_cents);
        $this->assertSame(3400, $quote->maps_metadata['distance_meters']);
        $this->assertSame(720, $quote->maps_metadata['duration_seconds']);
        $this->assertSame(-23.54, $quote->maps_metadata['origin']['latitude']);

        $address->forceFill(['street' => 'Rua alterada no cadastro', 'latitude' => -20.0, 'longitude' => -40.0])->save();
        $recalculated = app(DeliveryRoutingService::class)->recalculate($order->refresh());
        $this->assertSame(-23.55, $recalculated->maps_metadata['destination']['latitude']);
        $this->assertSame(-46.63, $recalculated->maps_metadata['destination']['longitude']);
        $this->assertSame('Rua de teste', $order->refresh()->delivery_address_snapshot['street']);
    }

    public function test_whatsapp_location_is_snapshotted_without_becoming_a_saved_customer_address(): void
    {
        $company = $this->company();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de localização']);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'ENT-LOC-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'active_order_id' => $order->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => 'manual',
            'whatsapp_identifier' => '5511999999999',
        ]);

        app(WhatsAppDeliveryLocationCapture::class)->capture($conversation->load('activeOrder'), new IncomingWhatsAppMessage(
            provider: 'fake', providerAccountId: null, providerMessageId: 'location-test', from: '5511999999999', to: null,
            senderName: null, messageType: 'location', text: null, sentAt: null,
            rawPayload: ['location' => ['latitude' => -23.55, 'longitude' => -46.63]],
        ));

        $snapshot = $order->refresh()->delivery_address_snapshot;
        $this->assertSame(-23.55, $snapshot['latitude']);
        $this->assertSame(-46.63, $snapshot['longitude']);
        $this->assertSame('whatsapp_location', $snapshot['location_source']);
        $this->assertNull($order->delivery_address_id);
        $this->assertSame(0, CustomerAddress::query()->count());
    }

    public function test_authorized_operator_can_save_structured_address_and_recalculate_route(): void
    {
        [$company, $order, $user] = $this->manualAddressFixture();
        $this->app->instance(DeliveryGeocodingProviderInterface::class, new class implements DeliveryGeocodingProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): GeocodedDeliveryAddress
            {
                return new GeocodedDeliveryAddress('Rua Nova, 45', new DeliveryCoordinates(-16.32, -48.95), 'fake');
            }
        });
        $this->app->instance(DeliveryRouteProviderInterface::class, new class implements DeliveryRouteProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute
            {
                return new DeliveryRoute(2500, 600, 'fake', 'manual-address-route');
            }
        });

        $this->actingAs($user)
            ->patchJson("/api/app/orders/{$order->id}/delivery/address", $this->manualAddressPayload())
            ->assertOk()
            ->assertJsonPath('warning', null)
            ->assertJsonPath('data.address.street', 'Rua Nova')
            ->assertJsonPath('data.address.number', '45')
            ->assertJsonPath('data.distance_meters', 2500)
            ->assertJsonPath('data.final_fee_cents', 750);

        $this->assertSame(0, CustomerAddress::query()->count());
        $this->assertSame('Rua Nova', $order->refresh()->delivery_address_snapshot['street']);
        $this->assertSame(-16.32, $order->delivery_address_snapshot['latitude']);
        $this->assertSame(Order::DELIVERY_STATUS_QUOTED, $order->refresh()->delivery_status);
    }

    public function test_geocoding_failure_keeps_manual_address_pending_and_permission_is_enforced(): void
    {
        [$company, $order, $user] = $this->manualAddressFixture();
        $unauthorized = User::factory()->create(['company_id' => $company->id]);
        $this->app->instance(DeliveryGeocodingProviderInterface::class, new class implements DeliveryGeocodingProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): never
            {
                throw new DomainException('Falha controlada de geocodificação.');
            }
        });

        $this->actingAs($unauthorized)
            ->patchJson("/api/app/orders/{$order->id}/delivery/address", $this->manualAddressPayload())
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/app/orders/{$order->id}/delivery/address", $this->manualAddressPayload())
            ->assertOk()
            ->assertJsonPath('data.address.street', 'Rua Nova')
            ->assertJsonPath('data.status', Order::DELIVERY_STATUS_ADDRESS_PENDING)
            ->assertJsonPath('data.quote_id', null)
            ->assertJsonPath('data.destination', null)
            ->assertJsonPath('data.final_fee_cents', 0)
            ->assertJson(fn ($json) => $json->whereType('warning', 'string')->etc());

        $this->assertSame(0, CustomerAddress::query()->count());
        $this->assertSame('Rua Nova', $order->refresh()->delivery_address_snapshot['street']);
        $this->assertSame(Order::DELIVERY_STATUS_ADDRESS_PENDING, $order->refresh()->delivery_status);
        $this->assertNull($order->delivery_calculated_at);
    }

    public function test_manual_order_address_is_saved_to_customer_only_when_explicitly_requested(): void
    {
        [$company, $order, $user] = $this->manualAddressFixture();
        $this->app->instance(DeliveryGeocodingProviderInterface::class, new class implements DeliveryGeocodingProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): never
            {
                throw new DomainException('Sem geocoding no teste.');
            }
        });

        $this->actingAs($user)->patchJson("/api/app/orders/{$order->id}/delivery/address", [
            ...$this->manualAddressPayload(),
            'label' => 'Trabalho',
            'save_to_customer' => true,
        ])->assertOk();

        $this->assertDatabaseHas('customer_addresses', [
            'company_id' => $company->id,
            'customer_id' => $order->payer_customer_id,
            'label' => 'Trabalho',
            'street' => 'Rua Nova',
            'is_default' => true,
        ]);
    }

    public function test_operator_can_select_only_an_address_owned_by_the_order_customer(): void
    {
        [$company, $order, $user] = $this->manualAddressFixture();
        $address = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $order->payer_customer_id,
            'label' => 'Casa',
            'street' => 'Rua Salva',
            'number' => '10',
            'neighborhood' => 'Centro',
            'city' => 'Anápolis',
            'state' => 'GO',
            'is_default' => true,
        ]);
        $anotherCustomer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Outro cliente']);
        $foreignAddress = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $anotherCustomer->id,
            'label' => 'Outro',
            'street' => 'Rua Incorreta',
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/delivery/address/{$address->id}/select")
            ->assertOk()
            ->assertJsonPath('data.address.street', 'Rua Salva')
            ->assertJsonPath('data.destination', null);
        $this->assertSame($address->id, $order->refresh()->delivery_address_id);
        $this->assertSame('Rua Salva', $order->delivery_address_snapshot['street']);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/delivery/address/{$foreignAddress->id}/select")
            ->assertNotFound();
    }

    /** @return array{0: Company, 1: Order, 2: User} */
    private function manualAddressFixture(): array
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $company = $this->company();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de entrega']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ATENDENTE);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'ENT-MANUAL-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'subtotal_cents' => 1500,
            'total_cents' => 1500,
            'amount_due_cents' => 1500,
        ]);
        DeliverySetting::query()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'calculation_mode' => DeliveryPricingService::MODE_PER_KM,
            'price_per_km_cents' => 300,
            'provider_options' => ['origin' => ['latitude' => -16.31, 'longitude' => -48.94]],
        ]);

        return [$company, $order, $user];
    }

    /** @return array<string, string> */
    private function manualAddressPayload(): array
    {
        return [
            'postal_code' => '75000-000',
            'street' => 'Rua Nova',
            'number' => '45',
            'complement' => 'Sala 2',
            'neighborhood' => 'Centro',
            'city' => 'Anápolis',
            'state' => 'GO',
            'reference' => 'Próximo à praça',
        ];
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'Restaurante de teste '.uniqid(),
            'slug' => 'restaurante-teste-'.uniqid(),
        ]);
    }

    /** @return array{0: Company, 1: User} */
    private function deliverySettingsActor(): array
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $company = $this->company();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$company, $user];
    }
}
