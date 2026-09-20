# Bot API — быстрый старт (CRM v1)

## Endpoint

`POST https://fixarivan.space/api/bot/lead.php`

## Авторизация

```http
Content-Type: application/json
X-FixariVan-Api-Key: <ваш_ключ>
X-Idempotency-Key: <uuid>   # опционально; для WhatsApp достаточно trigger_message_id
```

Ключ: **Админ → Настройки → «Сгенерировать ключ»** (`admin/settings.php`), либо env `FIXARIVAN_BOT_API_KEY`.

**n8n:** создайте credential типа **Header Auth**:
- Header name: `X-FixariVan-Api-Key`
- Header value: ключ из настроек CRM (не коммитить, не логировать)

## WhatsApp (n8n AI Worker → CRM)

**Endpoint (prod):** `POST https://fixarivan.space/api/bot/lead.php`

Обязательно для WhatsApp-лида:

| Поле | Правило |
|------|---------|
| `lead_source` | `whatsapp` |
| `chat_id` | JID вида `358401234567@s.whatsapp.net` **или** `phone` с тем же номером |
| `trigger_message_id` | Непустой ID исходного сообщения (Evolution message id) |
| `language` | `ru` / `fi` / `en` |
| `summary` или `problem_description` | Непустое описание (мин. 3 символа) |

**Не принимается:** `@lid` без достоверного номера телефона — CRM вернёт `400`, `success: false`.

**Идемпотентность:** повтор того же `trigger_message_id` не создаёт вторую заявку. CRM автоматически строит ключ `wa:msg:{trigger_message_id}`.

### Пример безопасного payload (WhatsApp, received)

```json
{
  "lead_source": "whatsapp",
  "chat_id": "358401234567@s.whatsapp.net",
  "trigger_message_id": "3EB0C767F26DEECBB830",
  "language": "fi",
  "client_name": "Mika",
  "lead_state": "received",
  "summary": "Asiakas pyytää apua kannettavan kanssa",
  "priority": "normal",
  "completion_score": 15
}
```

### Пример ответа (успех)

```json
{
  "success": true,
  "created": true,
  "idempotent_replay": false,
  "visible_in_track": false,
  "lead_state": "received",
  "document_id": "ORD-20260920120000-abc123",
  "trigger_message_id": "3EB0C767F26DEECBB830",
  "idempotency_key": "wa:msg:3EB0C767F26DEECBB830"
}
```

### Пример ответа (ошибка — без ложного success)

```json
{
  "success": false,
  "error": "lead_rejected",
  "message": "trigger_message_id is required for WhatsApp leads"
}
```

## Pipeline (до Track)

Пока GPT собирает информацию — **карточка в Track не показывается**.

| `lead_state` | CRM | Track |
|---|---|---|
| `received` | Получено | скрыто |
| `analyzing` | Анализируется | скрыто |
| `ready_for_review` | Готово к проверке | **🤖 Требуют проверки** |

Ответ API: `"visible_in_track": false` до `ready_for_review`.

## Priority (вычисляет GPT, не инженер)

| `priority` | Когда ставить |
|---|---|
| `normal` | обычное обращение |
| `high` | «нужен ноутбук завтра», срочный дедлайн, бизнес простаивает частично |
| `urgent` | «компания не может работать», полный простой, критичная инфраструктура |

При повторных POST сохраняется **более высокий** приоритет (normal < high < urgent).

## Пример: начало диалога

```json
{
  "phone": "+358 40 123 4567",
  "client_name": "Mika",
  "lead_source": "whatsapp",
  "chat_id": "wa-12345",
  "lead_state": "received",
  "priority": "normal",
  "completion_score": 10
}
```

## Пример: сбор данных

```json
{
  "phone": "+358 40 123 4567",
  "chat_id": "wa-12345",
  "lead_state": "analyzing",
  "priority": "high",
  "completion_score": 45,
  "summary": "MacBook не включается, нужен завтра к 10:00"
}
```

## Пример: готово к проверке инженером

```json
{
  "phone": "+358 40 123 4567",
  "chat_id": "wa-12345",
  "lead_state": "ready_for_review",
  "priority": "high",
  "problem_description": "MacBook Pro 14 не включается после падения",
  "device_model": "MacBook Pro 14",
  "completion_score": 85,
  "summary": "Клиенту нужен ноутбук завтра утром. Падение с высоты ~1 м."
}
```

`problem_description` обязателен только при `lead_state: ready_for_review`.

## Ответ (фрагмент)

```json
{
  "success": true,
  "lead_state": "ready_for_review",
  "lead_state_label": "Готово к проверке",
  "visible_in_track": true,
  "priority": "high",
  "priority_label": "High",
  "pending_review": true,
  "review_url": "https://fixarivan.space/order_new.html?from_lead=1&document_id=ORD-..."
}
```

## n8n — рекомендуемый поток

1. Первое сообщение → `lead_state: received`
2. Каждое уточнение → `lead_state: analyzing`, обновлять `completion_score`, `summary`, `priority`
3. Достаточно данных → `lead_state: ready_for_review` + `problem_description`
4. Инженер в Track → «Проверить и дополнить» или «Подтвердить в работу»

Полная спецификация: `docs/BOT_CRM_INTEGRATION_SPEC.md`
