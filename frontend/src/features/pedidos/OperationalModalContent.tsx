import type { AppModal } from '../../types/crm'

export function OperationalModalContent({ modal }: { modal: AppModal }) {
  if (modal === 'confirm-payment') {
    return (
      <div className="modal-fields">
        <label>
          Status
          <select defaultValue="revisao">
            <option value="revisao">Conferencia humana</option>
            <option value="confirmado">Confirmado pelo atendente</option>
          </select>
        </label>
        <label>
          Observacao
          <textarea placeholder="Registrar decisao sem anexar comprovante real." />
        </label>
      </div>
    )
  }

  if (modal === 'toggle-ai') {
    return (
      <div className="mode-options">
        <label>
          <input defaultChecked name="mode" type="radio" />
          IA assistindo com revisao humana
        </label>
        <label>
          <input name="mode" type="radio" />
          Atendimento manual
        </label>
        <p>A IA nunca confirma ambiguidades, pagamentos, credito ou entrega sozinha.</p>
      </div>
    )
  }

  if (modal === 'print-error') {
    return (
      <div className="modal-fields">
        <p>Opcoes previstas: tentar novamente, reimprimir, copiar comanda ou marcar impresso manualmente.</p>
        <label>
          Motivo operacional
          <textarea placeholder="Descreva a falha de impressao de forma objetiva." />
        </label>
      </div>
    )
  }

  if (modal === 'whatsapp-error') {
    return (
      <div className="modal-fields">
        <p>Verifique provider, webhook e variaveis seguras. Tokens reais nao devem aparecer na interface.</p>
        <label>
          Diagnostico
          <input placeholder="Sem conexao ou configuracao ausente" />
        </label>
      </div>
    )
  }

  return (
    <div className="modal-fields">
      <label>
        Motivo
        <textarea placeholder="Registre um motivo operacional seguro." />
      </label>
      <label>
        Proxima acao
        <input placeholder="Ex.: conferir com cliente antes de finalizar" />
      </label>
    </div>
  )
}
