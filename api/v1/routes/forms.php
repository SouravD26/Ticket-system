<?php
/**
 * Department daily-task forms - Digital, Events, Reporting and IT log their day
 * in a Google Form. The app draws the form from `forms` and posts answers to
 * `forms/submit`; the server forwards them to the same Google Form the web
 * Daily Tasks page uses, so they land in its responses / Sheet.
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/dept_forms.php';

/** The signed-in user's department form, or null when their department has none. */
function forms_for(array $user): ?array {
    return DEPT_FORMS[(string)($user['department'] ?? '')] ?? null;
}

/** GET forms - the user's form with every question and its prefilled value. */
function forms_index(mysqli $conn): void {
    require_method('GET');
    $user = auth_user($conn);
    $form = forms_for($user);
    if (!$form) ok(['has_form' => false, 'department' => $user['department'] ?? null, 'form' => null]);

    $prefill = [
        'name'  => $user['name'] ?? '',
        'today' => date('Y-m-d'),
        'now'   => date('H:i'),
        'dept'  => $user['department'] ?? '',
        'email' => $user['email'] ?? '',
    ];

    $fields = [];
    if ($form['collect_email']) {
        $fields[] = ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true,
                     'options' => [], 'is_duration' => false, 'value' => $prefill['email']];
    }
    foreach ($form['fields'] as $fd) {
        $fields[] = [
            'key'         => (string)$fd['entry'],
            'type'        => $fd['type'],           // text | textarea | radio | checkbox | date | time
            'label'       => $fd['label'],
            'required'    => $fd['required'],
            'options'     => $fd['options'] ?? [],
            'is_duration' => !empty($fd['duration']), // time field meaning hours:minutes spent
            'value'       => isset($fd['prefill']) ? $prefill[$fd['prefill']] : ($fd['type'] === 'checkbox' ? [] : ''),
        ];
    }

    ok([
        'has_form'   => true,
        'department' => $user['department'],
        'form'       => [
            'label'  => $form['label'],
            'title'  => $form['title'],
            'fields' => $fields,
        ],
    ]);
}

/** POST forms/submit - `answers`: { "<key>": value }; checkbox = array, date = YYYY-MM-DD, time = HH:MM. */
function forms_submit(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);
    $form = forms_for($user);
    if (!$form) fail('Your department has no daily task form.', 404, 'no_form');

    // `answers_b64` (base64 of the answers JSON) gets past the host's ModSecurity,
    // which rejects plain bodies carrying links (https://...) with 406 Not Acceptable.
    $packed = param('answers_b64');
    $answers = is_string($packed) && $packed !== ''
        ? json_decode((string)base64_decode($packed, true), true)
        : param('answers', []);
    if (is_string($answers)) $answers = json_decode($answers, true);
    if (!is_array($answers)) fail('`answers` must be an object keyed by field key.', 422, 'validation_error');

    $res = dept_form_submit($form, $answers);
    if ($res['ok']) ok(['submitted' => true], ['message' => $res['message']]);
    if ($res['missing']) fail($res['message'], 422, 'validation_error', ['missing' => $res['missing']]);
    fail($res['message'], 502, 'google_unreachable');
}
