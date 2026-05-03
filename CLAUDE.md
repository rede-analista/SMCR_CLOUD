# CLAUDE.md — SMCR_CLOUD

## Regra de paridade obrigatória

**Tudo que for alterado no SMCR_CLOUD DEVE ser replicado no SMCR_HA e vice-versa.**
Única exceção: features exclusivas do Home Assistant (Ingress, db_migrate.sh, portas do addon).

---

## Decisões arquiteturais — NÃO REVERTER

### `api/get_config.php` — campos cloud omitidos intencionalmente
`cloud_url`, `cloud_port` e `cloud_use_https` foram **removidos da resposta**.

**Por quê:** Se o cloud retornar esses campos com os defaults do banco (ex: cloud_port=443),
o ESP aplica esses valores e pode perder conexão com o servidor — exigindo deslocamento físico
ao local para corrigir via cabo serial. O ESP já usa esses valores para se conectar; reenviar
os do banco sobrescreveria os corretos do ESP.

**Como fica:** O ESP é a fonte de verdade para cloud_url/port/https. O cloud só recebe
esses valores (via heartbeat, sync e registro) — nunca os envia de volta.

---

### `api/register.php` — comportamento por tipo de dispositivo

**Novo dispositivo:** `ativo=0` (inativo).

**Por quê:** Com ativo=1, o ESP recebia a config zerada do cloud no próximo sync automático
(0 pinos, cloud_port=default) e sobrescrevia sua própria config correta. Com ativo=0,
get_config.php retorna 403 e o firmware não aplica nada — config local intacta.

**Dispositivo existente (re-registro):** Preserva o `ativo` atual. Não forçar ativo=1.

**Por quê:** Dispositivo pode estar inativo por decisão do usuário (mDNS discovery, revisão
pendente). Forçar ativo=1 ignora essa intenção.

**Dispositivo mDNS sem token:** Gera novo token. Não retornar null.

**Por quê:** mDNS insere device com api_token vazio. Se ESP se registra e recebe null,
fica sem token funcional.

**Config completa no registro:** register.php importa do payload: `cloud_port`,
`cloud_use_https`, sync/heartbeat settings, `pins`, `actions`, `intermod_modules`.

**Por quê:** O ESP envia sua config atual no registro (firmware v2.3.39+). Se o servidor
ignorar e usar defaults, o próximo sync cloud→ESP envia dados errados. O registro é o
único momento em que o cloud recebe cloud_port/https confiáveis do ESP.

---

### `api/sync_device.php` — porta de conexão ao ESP

Usa `COALESCE(ds.port, dc.web_server_port, 8080)` para determinar a porta do ESP.

**Por quê:** `device_status.port` é atualizado pelo heartbeat (porta real em uso pelo ESP).
`web_server_port` em `device_config` pode estar desatualizado. O heartbeat é mais recente.

**Sync manual funciona mesmo com `ativo=0`:** O bloqueio por `ativo` foi removido do
sync manual (permanece em get_config.php para sync automático do ESP).

**Por quê:** Sync manual é ação explícita do usuário — bloquear impede popular a config
do cloud antes de ativar o device.

---

### `api/status.php` — heartbeat de dispositivos inativos

Para `ativo=0`, retorna `{ok: true, ignored: true}` (HTTP 200).

**Por quê:** Se retornasse 403, o ESP trataria como erro e geraria alertas. Com ignored=true
o ESP não vê falha — o heartbeat simplesmente não é salvo no banco enquanto inativo.

---

## Fluxo de auto-registro (firmware v2.3.39+)

1. ESP chama `register.php` com config completa (cloud_port, pins, actions, intermod)
2. Servidor importa tudo, cria device com `ativo=0`
3. ESP tenta `get_config.php` → 403 → não aplica nada → config local preservada
4. ESP tenta heartbeat → `{ok:true, ignored:true}` → sem erro
5. Usuário revisa cadastro no cloud UI (já está completo e correto)
6. Usuário ativa device (`ativo=1`)
7. Próximos syncs: cloud devolve mesma config → sem alterações

---

## Fluxo de migração entre servidores

1. Alterar `cloud_url` (e `cloud_port`, `cloud_use_https`) no servidor antigo via push
2. ESP recebe nova URL → tenta novo servidor com token antigo → HTTP 401
3. Firmware apaga token local, dispara auto-registro
4. ESP se registra no novo servidor com config completa
5. Device nasce inativo no novo servidor — ativar após revisão

**Gatilho do auto-registro:** SOMENTE HTTP 401. HTTP 403, -1 (DNS fail) ou outros erros
NÃO disparam re-registro — o ESP continua tentando com o token atual.

---

## Deploy em produção

- Servidor: `smcr.pensenet.com.br`
- Usuário SSH: `rootadmin` | Alias: `smcr_cloud`
- Senha: em `/home/gczanatta/.local/share/Trash/files/Anotacoes.2.txt`
- Webroot: `/var/www/html`
- DB: `smcr_cloud` | User: `smrc`
- Comando de deploy:
  ```bash
  sshpass -p 'SENHA' ssh smcr_cloud "echo 'SENHA' | sudo -S git -C /var/www/html pull origin main"
  ```

---

## Migrações de banco em produção

Ao adicionar coluna nova em `schema.sql`, **aplicar também via SSH** no banco de produção:
```sql
ALTER TABLE tabela ADD COLUMN IF NOT EXISTS coluna TIPO DEFAULT valor;
```
E atualizar o default da coluna se necessário:
```sql
ALTER TABLE device_config MODIFY COLUMN cloud_port SMALLINT UNSIGNED NOT NULL DEFAULT 443;
```
O `UPDATE ... WHERE` existente não muda o default da coluna para novos inserts.
