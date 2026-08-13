<?php

namespace App\Services\WhatsApp;

use App\Data\WhatsApp\NormalizedWhatsAppAudio;
use App\Exceptions\WhatsAppAudioNormalizationException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class WhatsAppAudioNormalizer
{
    public function normalize(string $contents, string $detectedMimeType, string $filename): NormalizedWhatsAppAudio
    {
        $workspace = storage_path('app/whatsapp-tmp/'.bin2hex(random_bytes(12)));
        File::ensureDirectoryExists($workspace, 0700, true);
        $input = $workspace.'/input'.WhatsAppMediaFilename::extensionFor($detectedMimeType);
        $output = $workspace.'/output.ogg';

        try {
            File::put($input, $contents);
            $source = $this->probe($input);
            $inputMimeType = WhatsAppAudioFormat::canonicalize($detectedMimeType);
            $codec = strtolower((string) data_get($source, 'codec_name', ''));
            $format = strtolower((string) data_get($source, 'format_name', ''));

            if (WhatsAppAudioFormat::isWhatsAppOutboundType($inputMimeType)) {
                return new NormalizedWhatsAppAudio(
                    contents: $contents,
                    mimeType: $inputMimeType,
                    filename: WhatsAppMediaFilename::forMedia($filename, $inputMimeType, 'audio'),
                    action: 'pass_through',
                    metadata: ['input_format' => $format, 'input_codec' => $codec],
                );
            }

            if ($codec === 'opus') {
                $this->run([$this->ffmpegBinary(), '-y', '-v', 'error', '-i', $input, '-map', '0:a:0', '-c:a', 'copy', '-f', 'ogg', $output], 'whatsapp_audio_remux_failed');
                $action = 'remux';
            } else {
                $this->run([$this->ffmpegBinary(), '-y', '-v', 'error', '-i', $input, '-map', '0:a:0', '-c:a', 'libopus', '-b:a', '32k', '-f', 'ogg', $output], 'whatsapp_audio_transcode_failed');
                $action = 'transcode';
            }

            $normalized = $this->probe($output);
            if (strtolower((string) data_get($normalized, 'codec_name')) !== 'opus') {
                throw new WhatsAppAudioNormalizationException('whatsapp_audio_output_invalid', 'Não foi possível preparar a gravação para envio.');
            }

            $normalizedContents = File::get($output);
            if ($normalizedContents === '') {
                throw new WhatsAppAudioNormalizationException('whatsapp_audio_output_empty', 'Não foi possível preparar a gravação para envio.');
            }

            return new NormalizedWhatsAppAudio(
                contents: $normalizedContents,
                mimeType: 'audio/ogg',
                filename: WhatsAppMediaFilename::forMedia($filename, 'audio/ogg', 'audio'),
                action: $action,
                metadata: [
                    'input_format' => $format,
                    'input_codec' => $codec,
                    'output_format' => strtolower((string) data_get($normalized, 'format_name', '')),
                    'output_codec' => strtolower((string) data_get($normalized, 'codec_name', '')),
                ],
            );
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        $process = $this->run([$this->ffprobeBinary(), '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=codec_name,sample_rate:format=format_name,duration', '-of', 'json', $path], 'whatsapp_audio_probe_failed');
        $decoded = json_decode($process->getOutput(), true);
        $stream = is_array($decoded) ? data_get($decoded, 'streams.0') : null;

        if (! is_array($stream) || ! is_string($stream['codec_name'] ?? null)) {
            throw new WhatsAppAudioNormalizationException('whatsapp_audio_probe_failed', 'Não foi possível processar o áudio gravado.');
        }

        return [
            'codec_name' => $stream['codec_name'],
            'sample_rate' => $stream['sample_rate'] ?? null,
            'format_name' => data_get($decoded, 'format.format_name'),
            'duration' => data_get($decoded, 'format.duration'),
        ];
    }

    /** @param list<string> $command */
    private function run(array $command, string $errorCode): Process
    {
        try {
            $process = new Process($command, timeout: 20);
            $process->run();
        } catch (Throwable) {
            throw new WhatsAppAudioNormalizationException('whatsapp_audio_tools_unavailable', 'Não foi possível preparar a gravação para envio.');
        }

        if (! $process->isSuccessful()) {
            throw new WhatsAppAudioNormalizationException($errorCode, 'Não foi possível processar o áudio gravado.');
        }

        return $process;
    }

    private function ffmpegBinary(): string
    {
        return (string) config('chatbotcrm.whatsapp.media.ffmpeg_binary', 'ffmpeg');
    }

    private function ffprobeBinary(): string
    {
        return (string) config('chatbotcrm.whatsapp.media.ffprobe_binary', 'ffprobe');
    }
}
