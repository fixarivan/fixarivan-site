# Mobile Report MVP

## Измененные файлы

- `diagnostic_mobile.html`
- `api/save_report_fixed.php`
- `report_view.php`

## URL для проверки

- Форма: `http://localhost/fixarivan.space/diagnostic_mobile.html`
- Viewer: `http://localhost/fixarivan.space/report_view.php?token=<TOKEN>`

## Рабочий flow

1. Открыть форму `diagnostic_mobile.html`.
2. Заполнить поля и отправить форму.
3. Форма отправляет JSON в `api/save_report_fixed.php`.
4. API генерирует `report_id` и `token`.
5. API сохраняет JSON в `storage/reports/{token}.json`.
6. Клиент делает redirect на `report_view.php?token=...`.
7. Viewer показывает данные отчета в readonly режиме.

## Где хранится JSON

- Директория: `storage/reports`
- Формат файла: `{token}.json`
- Пример пути: `storage/reports/9f0e2b7ac4d1e8f5a3b6c9d0e1f2a4b8.json`

## Пример token URL

- `http://localhost/fixarivan.space/report_view.php?token=9f0e2b7ac4d1e8f5a3b6c9d0e1f2a4b8`
