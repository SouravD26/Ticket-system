<?php
/**
 * Department Google Forms, rebuilt as native fields on the Daily Tasks page.
 * Answers are posted to each form's formResponse address, so they land in the
 * same Google Form responses / Sheet. Entry ids come from the live forms - if a
 * question is added or changed in Google Forms, update it here too.
 *
 * prefill: name = employee name, today = today's date, now = current time, dept = department, email = user email.
 */
const DEPT_FORMS = [
    'Digital' => [
        'label' => 'Digital Team',
        'title' => 'DIGITAL TEAM REPORT OCT, 2026',
        'id'    => '1FAIpQLSfZbYBSJz4DOTcYxGhm8ydhps2t7G_gpf5fMr1IANJKwsMlLQ',
        'collect_email' => false,
        'fields' => [
            ['entry' => 1871004027, 'type' => 'text', 'label' => 'NAME OF PERSON', 'required' => true, 'prefill' => 'name'],
            ['entry' => 462958322, 'type' => 'date', 'label' => 'DATE', 'required' => true, 'prefill' => 'today'],
            ['entry' => 952120863, 'type' => 'time', 'label' => 'TIME', 'required' => true, 'prefill' => 'now'],
            ['entry' => 2022443920, 'type' => 'text', 'label' => 'NAME OF ASSIGNMENT', 'required' => true],
            ['entry' => 1927916591, 'type' => 'time', 'label' => 'DURATION OF TIME SPENT ON COMPLETING TASK', 'required' => true, 'duration' => true],
            ['entry' => 137758288, 'type' => 'text', 'label' => 'ASSIGNED BY', 'required' => true],
            ['entry' => 44015529, 'type' => 'checkbox', 'label' => 'POSTED ON', 'required' => true, 'options' => ['FACEBOOK', 'TWITTER', 'INSTAGRAM', 'LINKEDIN', 'WEBSITE', 'PRINT', 'WHATSAPP', 'SANMARG NETWORK', 'SUPER NEWS', 'NEWZSTREET']],
            ['entry' => 1186736283, 'type' => 'radio', 'label' => 'PAID OR UNPAID', 'required' => true, 'options' => ['PAID', 'UNPAID']],
            ['entry' => 1575051630, 'type' => 'radio', 'label' => 'TAGGING AND SENDING TO SOURCE PERSON', 'required' => true, 'options' => ['SENT VIA WATSAPP', 'NOT SENT']],
            ['entry' => 206760471, 'type' => 'text', 'label' => 'LINK OF THE POST OR WORK OR FILE NAME', 'required' => true],
        ],
    ],
    'Events' => [
        'label' => 'San Event',
        'title' => 'SAN EVENT TEAM TRACKER OCT,26',
        'id'    => '1FAIpQLSdcOGIo9gXyou6H9O_g-18mMh7coiYDlYOUeMaC-j0N6QfLVQ',
        'collect_email' => true,
        'fields' => [
            ['entry' => 226951141, 'type' => 'text', 'label' => 'Task', 'required' => true],
            ['entry' => 666881701, 'type' => 'text', 'label' => 'Task given by', 'required' => true],
            ['entry' => 17056359, 'type' => 'date', 'label' => 'Date', 'required' => true, 'prefill' => 'today'],
            ['entry' => 767711251, 'type' => 'time', 'label' => 'start time', 'required' => true, 'prefill' => 'now'],
            ['entry' => 1195410476, 'type' => 'text', 'label' => 'TASK COLLABRATOR', 'required' => false],
            ['entry' => 1459441338, 'type' => 'time', 'label' => 'Time taken to complete task', 'required' => true, 'duration' => true],
            ['entry' => 450712696, 'type' => 'text', 'label' => 'Task completion summary report', 'required' => true],
            ['entry' => 2032428120, 'type' => 'radio', 'label' => 'FOLLOW up required', 'required' => true, 'options' => ['Yes', 'No', 'Maybe']],
            ['entry' => 359356683, 'type' => 'textarea', 'label' => 'if yes details', 'required' => false],
            ['entry' => 353694406, 'type' => 'text', 'label' => 'LINKNAME  OF WORK executed', 'required' => true],
        ],
    ],
    'Reporting' => [
        'label' => 'Reporting Department',
        'title' => 'REPORTING DEPARTMENT ACTIVITY OCT,26',
        'id'    => '1FAIpQLSc0rJ8LKJjmRH0hlpox71BcQHBB_kOzZ92NswzZWjX4_wkn7g',
        'collect_email' => false,
        'fields' => [
            ['entry' => 287471110, 'type' => 'text', 'label' => 'EMPLOYEE NAME', 'required' => true, 'prefill' => 'name'],
            ['entry' => 19553505, 'type' => 'text', 'label' => 'NAME OF ASSIGNMENT', 'required' => true],
            ['entry' => 106254465, 'type' => 'date', 'label' => 'DATE OF EVENT', 'required' => true, 'prefill' => 'today'],
            ['entry' => 1539226004, 'type' => 'time', 'label' => 'TIME OF EVENT', 'required' => true, 'prefill' => 'now'],
            ['entry' => 1543063958, 'type' => 'radio', 'label' => 'INSTRUCTION GIVEN BY TO ATTEND', 'required' => true, 'options' => ['RG', 'NEHA', 'SPS', 'WASIM', 'VG', 'MADHUR', 'AMAN', 'NAEN JI']],
            ['entry' => 816836052, 'type' => 'text', 'label' => 'CONTACT PERSON AT EVENT ( NON PR PERSON)', 'required' => true],
            ['entry' => 1373444770, 'type' => 'text', 'label' => 'PHONE NO', 'required' => true],
            ['entry' => 2011465664, 'type' => 'text', 'label' => 'EMAIL', 'required' => false],
            ['entry' => 1884956045, 'type' => 'text', 'label' => 'ORGANISATION NAME', 'required' => true],
            ['entry' => 826647129, 'type' => 'time', 'label' => 'TIME TAKEN TO COVER THE EVENT INCLUDING TRAVEL', 'required' => true, 'duration' => true],
            ['entry' => 381093981, 'type' => 'radio', 'label' => 'STORY FILED', 'required' => true, 'options' => ['WEB', 'PRINT', 'SOCIAL', 'MULTIPLE PLATFORMS']],
            ['entry' => 1698423006, 'type' => 'text', 'label' => 'PR PERSON', 'required' => true],
            ['entry' => 663719023, 'type' => 'text', 'label' => 'PR ORGANIATION NAME', 'required' => true],
            ['entry' => 1634832627, 'type' => 'text', 'label' => 'PR CONTACT PHONE NO WATSAPP', 'required' => true],
            ['entry' => 302283595, 'type' => 'text', 'label' => 'PR PERSON EMAIL', 'required' => true],
            ['entry' => 521001712, 'type' => 'radio', 'label' => 'SOCIAL MEDIA HANDLES OF ORGANISER AND PR FIRM COLLECTED', 'required' => true, 'options' => ['YES PR PERSON', 'YES ORGANISER', 'NO']],
            ['entry' => 851600505, 'type' => 'textarea', 'label' => 'DIGNITARIES PRESENT', 'required' => true],
            ['entry' => 1721211899, 'type' => 'checkbox', 'label' => 'INTERVIEW TAKEN ALONGWITH', 'required' => true, 'options' => ['VIDEO', 'IMAGE']],
        ],
    ],
    'IT' => [
        'label' => 'IT',
        'title' => 'IT DEPT TICKET TRACKER OCT,26',
        'id'    => '1FAIpQLSeCvRBdZT85eABhNQqvRzy8dfjPK_jyMgbCVB-2iSXd3KsXWA',
        'collect_email' => false,
        'fields' => [
            ['entry' => 1737474116, 'type' => 'text', 'label' => 'Name of task', 'required' => true],
            ['entry' => 2099062199, 'type' => 'date', 'label' => 'Date task was given', 'required' => true, 'prefill' => 'today'],
            ['entry' => 542513162, 'type' => 'time', 'label' => 'Time Task was given', 'required' => true, 'prefill' => 'now'],
            ['entry' => 1854512210, 'type' => 'text', 'label' => 'Task given by', 'required' => true],
            ['entry' => 647624589, 'type' => 'text', 'label' => 'Department', 'required' => true, 'prefill' => 'dept'],
            ['entry' => 438411496, 'type' => 'text', 'label' => 'IT DEPT PERSON ASSIGNED TO HANDLE THE TASK', 'required' => true, 'prefill' => 'name'],
            ['entry' => 443685326, 'type' => 'text', 'label' => 'IT DEPT PERSON HO ASSIGNED THE TASK', 'required' => false],
            ['entry' => 2097519819, 'type' => 'checkbox', 'label' => 'TASK DESCRIPTION', 'required' => true, 'options' => ['BREAKDOWN', 'REPAIR', 'SOLUTION FROM OUTSIDE HELP', 'TRAINING ISSUE', 'OUT OF OFFICE WORK']],
            ['entry' => 1348320244, 'type' => 'checkbox', 'label' => 'TASK COMPLETED', 'required' => true, 'options' => ['Yes', 'No']],
            ['entry' => 1222447565, 'type' => 'checkbox', 'label' => 'COMPLAINANT SATISFIED', 'required' => true, 'options' => ['YES', 'No']],
            ['entry' => 36761782, 'type' => 'time', 'label' => 'DURATION', 'required' => true, 'duration' => true],
            ['entry' => 628201396, 'type' => 'text', 'label' => 'ANY FURTHER ACTION TO BE TAKEN and REMARKS', 'required' => true],
        ],
    ],
];

/**
 * Validate answers and post them to the form's Google formResponse address.
 * $answers is keyed by entry id (checkbox = array of options, date = Y-m-d,
 * time = H:MM), plus 'email' when the form collects one.
 * Returns ['ok' => bool, 'message' => string, 'missing' => [labels]].
 */
function dept_form_submit(array $form, array $answers): array
{
    $pairs = [];
    $missing = [];
    foreach ($form['fields'] as $fd) {
        $k = 'entry.' . $fd['entry'];
        $v = $answers[$fd['entry']] ?? '';
        if ($fd['type'] === 'checkbox') {
            $v = array_values(array_filter(array_map('trim', (array) $v), 'strlen'));
            foreach ($v as $one) $pairs[] = [$k, $one];
        } elseif ($fd['type'] === 'date') {
            $v = trim((string) $v);
            if ($v && ($t = strtotime($v))) {
                $pairs[] = [$k . '_year', date('Y', $t)];
                $pairs[] = [$k . '_month', date('n', $t)];
                $pairs[] = [$k . '_day', date('j', $t)];
            } else $v = '';
        } elseif ($fd['type'] === 'time') {
            $v = trim((string) $v);
            if (preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) {
                $pairs[] = [$k . '_hour', str_pad($m[1], 2, '0', STR_PAD_LEFT)];
                $pairs[] = [$k . '_minute', $m[2]];
            } else $v = '';
        } else {
            $v = trim((string) (is_array($v) ? '' : $v));
            if ($v !== '') $pairs[] = [$k, $v];
        }
        if ($fd['required'] && ($v === '' || $v === [])) $missing[] = $fd['label'];
    }
    if ($form['collect_email']) {
        $email = trim((string) ($answers['email'] ?? ''));
        if ($email === '') $missing[] = 'Email';
        $pairs[] = ['emailAddress', $email];
    }
    if ($missing) {
        return ['ok' => false, 'message' => 'Please fill: ' . implode(', ', $missing), 'missing' => $missing];
    }

    $body = implode('&', array_map(fn($p) => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $pairs));
    $ch = curl_init('https://docs.google.com/forms/d/e/' . $form['id'] . '/formResponse');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Google answers 200 only when it accepted the response; a rejected one comes back 400.
    if ($code === 200) {
        return ['ok' => true, 'message' => 'Submitted to the ' . $form['label'] . ' Google Form.', 'missing' => []];
    }
    return ['ok' => false, 'message' => 'Could not reach Google Forms (' . ($err ?: 'HTTP ' . $code) . '). Please try again.', 'missing' => []];
}
