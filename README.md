# Velfy PIX para WHMCS

Gateway de pagamento PIX da Velfy para WHMCS. Versão **0.1.6**. Exibe QR Code e código copia e cola na fatura e confirma o pagamento por consulta autenticada à API antes de registrar a baixa.

## Instalação

1. Baixe o pacote de instalação em [Releases](https://github.com/matheusfs10/velfy-pix-whmcs/releases).
2. Envie o conteúdo de `modules/` para a raiz do WHMCS, preservando os demais módulos e sobrescrevendo somente os arquivos da Velfy.
3. Abra **Configuration > Apps & Integrations > Payments**, localize **Velfy PIX** e clique em **Ativar**. Nas versões que usam a tela anterior, procure **System Settings > Payment Gateways**. Um módulo ativo apresenta **Gerenciar**.
4. Configure a chave **Bearer** no campo de senha do gateway e mantenha a origem da API como `https://api.velfy.com.br`.
5. Configure a validade do PIX e, se necessário, o ID do campo personalizado CPF/CNPJ. Em branco, o módulo utiliza o Tax ID do cadastro.
6. Use faturas e clientes em **BRL**, sem conversão de moeda pelo gateway. O cadastro precisa de nome, e-mail, telefone com DDD e CPF/CNPJ numérico válido.

Requer **PHP 8.0+**, extensões cURL, JSON e PDO/MySQL e uma instalação licenciada do WHMCS compatível com o PHP utilizado. Não há dependências PHP externas. A tabela `mod_velfypix_charges` é criada no primeiro uso; o usuário do banco precisa de permissão para criá-la.

O prefixo de instalação é opcional: em branco, deriva da URL do WHMCS. Se configurado, deve ser único por instalação e permanecer estável após a emissão das cobranças.

### Atualização

Atualize todos os arquivos do módulo Velfy contidos em `modules/`. A versão 0.1.6 altera PHP, CSS, JavaScript e metadados. Recarregue a fatura após o envio; os assets têm identificação de versão para atualizar o cache.

## Funcionalidades

- Cobrança PIX com valores em centavos, Bearer e `Idempotency-Key`.
- Botão compacto **Pagar com PIX** no cabeçalho da fatura.
- QR Code gerado localmente e código copia e cola em janela adaptada para computador e celular.
- Fechamento por botão, Escape ou fundo, com retorno do foco. Sem JavaScript, uma seção nativa expansível permite acessar o código.
- Uma cobrança persistida por fatura; recarregar reutiliza a cobrança existente.
- Repetição após falha com o mesmo corpo e a mesma chave de idempotência.
- Callback com consulta autenticada à API e conferência de transação, referência, método, valor, cliente, moeda e saldo da fatura.
- Prevenção de baixa duplicada e recuperação após interrupção posterior ao lançamento no WHMCS.
- Logo, descrição e categoria Payments na apresentação nativa de Apps & Integrations.

## Callback e confirmação

O módulo informa automaticamente esta `postbackUrl` em cada cobrança:

```text
https://SEU-WHMCS/modules/gateways/callback/velfypix.php
```

O endereço deve receber POST JSON por HTTPS, sem login ou desafio interativo. IDs desconhecidos não iniciam consultas à Velfy. A notificação funciona como gatilho: somente os dados da consulta Bearer podem autorizar a baixa. Headers de evento, entrega e tentativa não são tratados como assinaturas criptográficas.

Novas cobranças usam callback sem query. Tokens legados, quando enviados, precisam corresponder ao hash persistido. A confirmação pela API continua obrigatória, com ou sem token.

O lançamento usa o valor bruto e registra a taxa separadamente. O identificador no WHMCS é `velfypix:ID_DA_TRANSACAO`. O status que permite pagamento é `paid`, no método `pix`, com o valor pago exatamente igual ao da cobrança e sem estorno.

## Comportamentos e limites

- O item enviado representa o saldo da fatura, com quantidade 1 e `tangible: false`.
- Mudança de valor, cliente ou origem da API após a emissão exige conciliação; o módulo não cria outra cobrança automaticamente.
- PIX vencido não é reemitido automaticamente.
- Estornos, devoluções, chargebacks, cartão e boleto não são automatizados nesta versão.
- Não há cron de conciliação. Sem entrega de uma notificação, a confirmação não é iniciada automaticamente.
- Locks MySQL serializam as operações deste módulo por fatura. Alterações concorrentes feitas por outros gateways ou administradores precisam ser conferidas na instalação real.
- O corpo de criação fica persistido enquanto a emissão não foi confirmada, permitindo uma repetição idêntica. Depois, é removido. Os dados PIX mantêm apenas código, validade e status remoto.
- O módulo não envia chave API, CPF, e-mail, telefone ou corpo bruto da API para o HTML da fatura ou os logs do gateway.

O contrato dos campos e as verificações estão em [docs/contrato-api.md](docs/contrato-api.md).

## Desenvolvimento e validação

```sh
composer test
composer lint
composer preview
```

Os testes precisam de `pdo_sqlite`; o cenário com dois processos utiliza `pcntl` quando disponível. Os **46 cenários locais** usam Velfy e WHMCS simulados, cobrindo valores, idempotência, callbacks, duplicatas, recuperação, concorrência, limites da API e proteção dos dados na apresentação e nos logs.

A demonstração abre em `http://127.0.0.1:8765`, usa somente dados fictícios e não chama a API real. As páginas `/fatura` e `/ativacao` permitem conferir o layout de fatura e a apresentação do gateway. O servidor de demonstração aceita somente acesso por loopback.

Os exemplos em [tests/fixtures](tests/fixtures) têm identificadores, documentos, contatos, datas e URLs fictícios. Não são capturas brutas de produção. Credenciais, registros reais, bancos locais e backups ficam excluídos do Git e do pacote.

O layout foi conferido também em uma instalação real. A baixa automática, os hooks, e-mails, provisionamento e alterações concorrentes de saldo devem ser validados no WHMCS de destino; os stubs locais não substituem essa etapa.

### Gerar o pacote

```sh
composer package
```

Gera `dist/velfy-pix-whmcs-0.1.6.zip` com o módulo, documentação e `SHA256SUMS.json`. Testes, demonstração, dados locais e credenciais ficam fora do pacote. O ZIP da release contém a mesma implementação do gateway que o código-fonte.

## Histórico recente

- **0.1.6:** botão compacto no cabeçalho e janela PIX adaptável, com identificação de versão nos assets.
- **0.1.5:** categoria `payments` para a listagem em Apps & Integrations.
- **0.1.4:** callback sem query e confirmação financeira obrigatória por consulta Bearer; compatibilidade com tokens legados.

## Referências e componentes

A logo Velfy usa PNG transparente de 384 × 73 pixels, convertido do WebP original incluído nos assets. O QR Code utiliza QRCode.js, com versão fixada e licença MIT incluída em `assets/QRCODE-LICENSE.txt`; a origem está em `assets/THIRD-PARTY.txt`.

Referências: [gateways do WHMCS](https://developers.whmcs.com/payment-gateways/third-party-gateway), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [metadados](https://developers.whmcs.com/advanced/json-file), [template Twenty-One](https://github.com/WHMCS/templates-twenty-one/blob/master/viewinvoice.tpl) e [Velfy](https://www.velfy.com.br/).
