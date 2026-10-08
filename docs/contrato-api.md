# Contrato da integração

Este documento descreve o adaptador implementado. Os exemplos e fixtures públicos são fictícios e preservam somente a estrutura necessária aos testes.

## Criação da cobrança

`POST https://api.velfy.com.br/api/v1/transactions`

| Elemento | Implementação |
| --- | --- |
| Autenticação | `Authorization: Bearer CHAVE_CONFIGURADA_NO_WHMCS` |
| Idempotência | `Idempotency-Key` igual à referência persistida da fatura |
| Valor | `amount` inteiro em centavos |
| Método | `paymentMethod: pix` |
| Referência | `externalRef` identifica instalação e fatura |
| Callback | `postbackUrl` HTTPS do módulo, sem query nas novas cobranças |
| Cliente | nome, e-mail, telefone e documento `{type, number}` |
| Item | título, `unitPrice`, quantidade 1 e `tangible: false` |
| Expiração | `pix.expiresInDays`, de 1 a 99 dias |
| Metadados | string JSON com origem WHMCS e ID da fatura |

A resposta precisa ter HTTP 200/201 e envelope `{ "success": true, "data": { ... } }`. A criação utiliza normalmente HTTP 201.

| Campo de `data` | Tratamento |
| --- | --- |
| `id` | Inteiro ou string normalizado para string; usado na consulta e no identificador do pagamento |
| `externalId` / `externalRef` | Deve corresponder à referência persistida; se ambos estiverem presentes, precisam concordar |
| `amount` | Inteiro em centavos, igual ao valor persistido |
| `paymentMethod` | Deve ser `pix` |
| `status` | `pending`, `processing` ou `paid` para uma cobrança utilizável |
| `pix.qrcode` / `pix.qrcodeText` | Texto copia e cola, com prefixo `000201` e limite de 4096 bytes |
| `pix.expirationDate` | ISO 8601, normalizado preservando o instante |
| `fees` | Taxa em centavos inteiros |

Um código PIX abreviado nas fixtures verifica a leitura do campo, mas não é pagável. O QR Code é renderizado localmente; nenhuma URL de imagem retornada pela API é carregada.

O pedido é salvo antes do POST. Em falhas ambíguas, a próxima tentativa utiliza exatamente o mesmo corpo e a mesma chave. Após a criação confirmada, `request_json` é removido. O registro PIX retém somente código, validade e status remoto.

## Consulta e webhook

`GET https://api.velfy.com.br/api/v1/transactions/{id}` utiliza o mesmo Bearer configurado. O módulo aceita o mesmo envelope de sucesso e confere o ID retornado.

O webhook tem `type: "transaction"` e os dados em `data`. O `id` da raiz representa a entrega; `data.id` representa a transação. A referência é `data.externalRef`, com suporte ao alias `externalId`.

Os headers `X-Velfy-Attempt`, `X-Velfy-Delivery-Id`, `X-Velfy-Event-Id` e `X-Velfy-Event-Type` descrevem a entrega. O módulo não os utiliza como prova de autenticação nem assume uma regra de assinatura criptográfica.

### Sequência de confirmação

1. Exigir POST JSON de até 128 KiB e um evento de transação válido.
2. Localizar a cobrança pelo ID persistido. Para recuperar uma resposta de criação perdida, aceitar a referência de uma cobrança já salva.
3. Rejeitar IDs/referências desconhecidos e tokens legados divergentes, sem consultar a API.
4. Obter lock por fatura e conferir se o pagamento já está no ledger do WHMCS.
5. Consultar a transação por Bearer, usando somente a origem configurada no gateway.
6. Conferir ID, referência, método, valor bruto, `paidAmount` e ausência de estorno.
7. Conferir cliente, moeda BRL, status aberto e saldo disponível da fatura.
8. Aplicar valor bruto e taxa separadamente, com ID `velfypix:ID_DA_TRANSACAO`.
9. Conferir a existência do lançamento antes de marcar a cobrança local como paga.

URLs, valores pagos, dados de cliente e status escritos no corpo do webhook não autorizam a baixa. O módulo não faz requisições às URLs presentes no evento.

Callbacks novos não dependem de token na query. Um token legado enviado deve ser hexadecimal de 64 caracteres e corresponder ao hash persistido, com comparação em tempo constante. Sua ausência não dispensa a consulta financeira autenticada.

Eventos repetidos, fora de ordem ou com outro ID de entrega não geram outro crédito quando o lançamento já existe. Uma interrupção após o lançamento também é recuperada por essa conferência. Um estado local pago sem lançamento correspondente exige conciliação.

## Valores e taxas

O módulo trabalha internamente com centavos inteiros. Para dar baixa, `status` deve ser `paid`, `paidAmount` deve ser exatamente igual ao valor persistido e `refundedAmount` deve ser zero.

A taxa é lida de `fees` ou calculada como `amount - fee.netAmount`. Quando ambos estiverem presentes, devem concordar. Taxas negativas, decimais, superiores ao bruto ou inconsistentes são rejeitadas. Sem os dois campos, a taxa é zero.

Por exemplo, `amount: 10000` e `fees: 949` representam pagamento de R$ 100,00 e taxa de R$ 9,49. A taxa não reduz o valor creditado à fatura.

## Persistência, transporte e logs

- MySQL utiliza a tabela própria `mod_velfypix_charges`, índices únicos de fatura/referência/transação e `GET_LOCK` por fatura. SQLite serve somente aos testes e à demonstração.
- A conexão cURL exige HTTPS e certificado válido, não segue redirecionamentos e tem limites de tempo e de resposta.
- Falhas de transporte e respostas inválidas geram mensagens controladas, sem corpo bruto, credenciais ou dados pessoais.
- O HTML escapa o código PIX e as mensagens; o QR Code utiliza a biblioteca local incluída no pacote.
- A chave API é configurada no WHMCS, sem valor padrão no código-fonte.

## Limites de validação

Os 46 cenários locais cobrem criação, idempotência, callbacks, entrega repetida, perda de resposta, taxas, valores divergentes, falha da API, concorrência e as funções de integração com stubs do WHMCS. As fixtures não contêm dados reais de clientes ou de contas.

O layout 0.1.6 mantém somente o botão no ponto `paymentbutton` do cabeçalho do WHMCS. O cartão PIX é movido para um diálogo fora da grade da fatura; em telas pequenas, o conteúdo usa uma coluna com rolagem interna. A seção expansível é a alternativa sem JavaScript ou suporte ao diálogo.

A confirmação real no ledger, os hooks, e-mails, provisionamento e alterações de saldo realizadas por outros gateways precisam de validação no WHMCS de destino. A versão atual não possui cron de conciliação, reemissão automática de PIX ou automação de estornos. A política formal de reenvio e uma eventual regra de assinatura da Velfy não estão especificadas neste contrato.

Referências: [gateways do WHMCS](https://developers.whmcs.com/payment-gateways/third-party-gateway), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [GetInvoice](https://developers.whmcs.com/api-reference/getinvoice) e [template Twenty-One](https://github.com/WHMCS/templates-twenty-one/blob/master/viewinvoice.tpl).
