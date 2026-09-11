<h1>Novo chamado de suporte</h1>
<p><strong>Ticket:</strong> {{ $ticket->code }}</p>
<p><strong>Empresa:</strong> {{ $ticket->company->name }}</p>
<p><strong>Usuário:</strong> {{ $ticket->user->name }}</p>
<p><strong>Categoria:</strong> {{ $ticket->category }}</p>
<p><strong>Prioridade:</strong> {{ $ticket->priority }}</p>
<p><strong>Assunto:</strong> {{ $ticket->subject }}</p>
<p><strong>Descrição:</strong><br>{{ $ticket->description }}</p>
<p><strong>Tela:</strong> {{ $ticket->current_route ?? 'Não informada' }}</p>
<p><strong>Data/hora:</strong> {{ $ticket->created_at?->format('d/m/Y H:i') }}</p>
