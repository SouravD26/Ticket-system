<?php
/**
 * Help desk tickets - raise one, follow it, reply, and sign the work off.
 *
 * The rules mirror the web app (ticket-new.php / ticket-view.php):
 *   - Super Admin and Admin see everything; Admin is read-only.
 *   - IT sees what is assigned to them, plus what they raised themselves.
 *   - Everyone else sees only their own tickets.
 *   - Only IT and the Super Admin change status, and never to "closed":
 *     a ticket is closed by the requester acknowledging the work.
 */
declare(strict_types=1);

const TICKET_STATUS  = ['open' => 'Open', 'pending' => 'In Progress', 'resolved' => 'Completed', 'closed' => 'Closed'];
/** Statuses IT staff may set themselves. */
const TICKET_IT_SET  = ['open', 'pending', 'resolved'];
const TICKET_PRIO    = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

/** Whoever may raise a ticket at all. */
function tickets_can_raise(array $u): bool {
    return in_array($u['role'], ['superadmin', 'employee', 'hr'], true);
}
/** IT and the Super Admin do the work. */
function tickets_can_work(array $u): bool {
    return in_array($u['role'], ['superadmin', 'it'], true);
}
/** Admin is a reporting role: it reads tickets but never writes. */
function tickets_read_only(array $u): bool {
    return $u['role'] === 'admin';
}

/** The visibility rule, as SQL, for whichever user is asking. */
function tickets_scope(array $u): array {
    $uid = (int)$u['id'];
    if (in_array($u['role'], ['superadmin', 'admin'], true)) return ['1', '', []];
    if ($u['role'] === 'it') return ['(t.assigned_to = ? OR t.user_id = ?)', 'ii', [$uid, $uid]];
    return ['t.user_id = ?', 'i', [$uid]];
}

/** One ticket the caller is allowed to see, or a 404/403. */
function tickets_find(mysqli $conn, array $u, int $id): array {
    if ($id <= 0) fail('Pass the ticket id.', 422, 'validation_error');
    [$where, $types, $params] = tickets_scope($u);
    $row = fetch_one(
        $conn,
        "SELECT t.*, u.name AS requester_name, u.employee_id AS requester_code,
                a.name AS agent_name, d.name AS dept_name
           FROM tickets t
           JOIN users u ON u.id = t.user_id
           LEFT JOIN users a ON a.id = t.assigned_to
           LEFT JOIN departments d ON d.id = t.department_id
          WHERE t.id = ? AND $where LIMIT 1",
        'i' . $types,
        array_merge([$id], $params)
    );
    if (!$row) fail('Ticket not found, or it is not yours.', 404, 'not_found');
    return $row;
}

function shape_ticket(array $t, array $u = []): array {
    $mine = $u && (int)$t['user_id'] === (int)$u['id'];
    return [
        'id'              => (int)$t['id'],
        'code'            => $t['code'],
        'subject'         => $t['subject'],
        'body'            => $t['body'],
        'status'          => $t['status'],
        'status_label'    => TICKET_STATUS[$t['status']] ?? $t['status'],
        'priority'        => $t['priority'],
        'priority_label'  => TICKET_PRIO[$t['priority']] ?? $t['priority'],
        'location'        => $t['location'],
        'trained_before'  => $t['trained_before'],
        'user_id'         => (int)$t['user_id'],
        'requester_name'  => $t['requester_name'] ?? null,
        'requester_code'  => $t['requester_code'] ?? null,
        'assigned_to'     => $t['assigned_to'] !== null ? (int)$t['assigned_to'] : null,
        'agent_name'      => $t['agent_name'] ?? null,
        'department_id'   => $t['department_id'] !== null ? (int)$t['department_id'] : null,
        'department'      => $t['dept_name'] ?? null,
        'reopen_count'    => (int)$t['reopen_count'],
        'created_at'      => $t['created_at'],
        'updated_at'      => $t['updated_at'],
        'assigned_at'     => $t['assigned_at'],
        'completed_at'    => $t['completed_at'],
        'acknowledged_at' => $t['acknowledged_at'],
        'closed_at'       => $t['closed_at'],
        // What this caller may do next, so the app can draw the right buttons.
        'can_reply'       => !empty($u) && !tickets_read_only($u),
        'can_acknowledge' => $mine && $t['status'] === 'resolved',
        'can_reraise'     => $mine && in_array($t['status'], ['resolved', 'closed'], true),
        'can_update'      => !empty($u) && tickets_can_work($u),
    ];
}

/** The next TKT-yy-0001 code, skipping any that is taken. */
function tickets_next_code(mysqli $conn): string {
    $n = (int)fetch_value($conn, "SELECT COUNT(*) FROM tickets") + 1;
    do {
        $code = 'TKT-' . date('y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        $taken = fetch_one($conn, "SELECT id FROM tickets WHERE code = ? LIMIT 1", 's', [$code]);
        $n++;
    } while ($taken);
    return $code;
}

function tickets_log(mysqli $conn, int $ticketId, int $userId, string $action, string $detail = ''): void {
    $stmt = $conn->prepare("INSERT INTO ticket_activity (ticket_id, user_id, action, detail) VALUES (?,?,?,?)");
    $stmt->bind_param('iiss', $ticketId, $userId, $action, $detail);
    $stmt->execute();
    $stmt->close();
}

/** GET tickets - the caller's list. status?, search?, page, per_page. */
function tickets_index(mysqli $conn): void {
    $user = auth_user($conn);
    $p    = paging(20);

    [$where, $types, $params] = tickets_scope($user);

    $status = (string)param('status', '');
    if ($status !== '' && isset(TICKET_STATUS[$status])) {
        $where   .= ' AND t.status = ?';
        $types   .= 's';
        $params[] = $status;
    } elseif ($status === 'active') {          // everything not yet closed
        $where .= " AND t.status <> 'closed'";
    }
    $q = (string)param('search', '');
    if ($q !== '') {
        $where   .= ' AND (t.subject LIKE ? OR t.code LIKE ?)';
        $types   .= 'ss';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $total = (int)fetch_value($conn, "SELECT COUNT(*) FROM tickets t WHERE $where", $types, $params);
    $rows  = fetch_all(
        $conn,
        "SELECT t.*, u.name AS requester_name, u.employee_id AS requester_code,
                a.name AS agent_name, d.name AS dept_name
           FROM tickets t
           JOIN users u ON u.id = t.user_id
           LEFT JOIN users a ON a.id = t.assigned_to
           LEFT JOIN departments d ON d.id = t.department_id
          WHERE $where
          ORDER BY t.updated_at DESC
          LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$p['limit'], $p['offset']])
    );

    $counts = [];
    foreach (fetch_all($conn, "SELECT t.status, COUNT(*) n FROM tickets t WHERE $where GROUP BY t.status", $types, $params) as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }

    ok(
        array_map(static fn(array $t): array => shape_ticket($t, $user), $rows),
        ['meta' => meta($p, $total) + ['counts' => $counts]]
    );
}

/** GET tickets/show?id= - one ticket with its conversation. */
function tickets_show(mysqli $conn): void {
    $user   = auth_user($conn);
    $ticket = tickets_find($conn, $user, param_int('id'));

    // Internal notes belong to the people working the ticket.
    $hideInternal = tickets_can_work($user) || tickets_read_only($user) ? '' : ' AND r.is_internal = 0';
    $replies = fetch_all(
        $conn,
        "SELECT r.id, r.message, r.is_internal, r.created_at, r.user_id, u.name AS author, u.role AS author_role
           FROM ticket_replies r JOIN users u ON u.id = r.user_id
          WHERE r.ticket_id = ?$hideInternal
          ORDER BY r.id",
        'i',
        [(int)$ticket['id']]
    );
    $activity = fetch_all(
        $conn,
        "SELECT a.action, a.detail, a.created_at, u.name AS author
           FROM ticket_activity a LEFT JOIN users u ON u.id = a.user_id
          WHERE a.ticket_id = ? ORDER BY a.id",
        'i',
        [(int)$ticket['id']]
    );

    ok([
        'ticket'   => shape_ticket($ticket, $user),
        'replies'  => array_map(static fn(array $r): array => [
            'id'          => (int)$r['id'],
            'message'     => $r['message'],
            'is_internal' => (bool)(int)$r['is_internal'],
            'user_id'     => (int)$r['user_id'],
            'author'      => $r['author'],
            'author_role' => $r['author_role'],
            'created_at'  => $r['created_at'],
        ], $replies),
        'activity' => array_map(static fn(array $a): array => [
            'action' => $a['action'], 'detail' => $a['detail'],
            'author' => $a['author'], 'created_at' => $a['created_at'],
        ], $activity),
    ]);
}

/** POST tickets/create { subject, body, location, trained_before } */
function tickets_create(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);
    if (!tickets_can_raise($user)) {
        fail('Your account is not allowed to raise tickets.', 403, 'forbidden');
    }

    $in = require_params(['subject', 'body', 'location', 'trained_before']);

    if (mb_strlen($in['subject']) < 5)  fail('Subject must be at least 5 characters.', 422, 'validation_error');
    if (mb_strlen($in['body']) < 10)    fail('Describe the issue in at least 10 characters.', 422, 'validation_error');

    $trained = strtolower((string)$in['trained_before']);
    if (!in_array($trained, ['yes', 'no'], true)) {
        fail('trained_before must be "yes" or "no".', 422, 'validation_error');
    }
    // The location list is the HRMS master list (tickets/locations returns it).
    $known = fetch_one($conn, "SELECT name FROM locations WHERE name = ? LIMIT 1", 's', [$in['location']]);
    if (!$known) fail('Unknown location. Call tickets/locations for the list.', 422, 'validation_error');

    // The department is always the requester's own; it is never taken from the request.
    $deptId  = $user['department_id'] !== null ? (int)$user['department_id'] : null;
    $code    = tickets_next_code($conn);
    $subject = mb_substr((string)$in['subject'], 0, 200);
    $body    = (string)$in['body'];
    $uid     = (int)$user['id'];

    $stmt = $conn->prepare(
        "INSERT INTO tickets (code, subject, body, user_id, department_id, location, trained_before)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('sssiiss', $code, $subject, $body, $uid, $deptId, $in['location'], $trained);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not create the ticket. Please try again.', 500, 'db_error');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    tickets_log($conn, $id, $uid, 'created', 'Ticket opened');
    ok(['message' => 'Ticket ' . $code . ' has been created.',
        'ticket'  => shape_ticket(tickets_find($conn, $user, $id), $user)]);
}

/** POST tickets/reply { id, message, is_internal? } */
function tickets_reply(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);
    if (tickets_read_only($user)) fail('Admins have read-only access to tickets.', 403, 'forbidden');

    $ticket = tickets_find($conn, $user, param_int('id'));
    $in     = require_params(['message']);
    $msg    = (string)$in['message'];

    $internal = tickets_can_work($user) && (string)param('is_internal', '0') === '1' ? 1 : 0;
    $tid = (int)$ticket['id'];
    $uid = (int)$user['id'];

    $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,?)");
    $stmt->bind_param('iisi', $tid, $uid, $msg, $internal);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not post your reply.', 500, 'db_error');
    }
    $replyId = (int)$conn->insert_id;
    $stmt->close();

    $conn->query("UPDATE tickets SET updated_at = NOW() WHERE id = $tid");
    tickets_log($conn, $tid, $uid, 'replied', $internal ? 'Internal note added' : 'Reply added');

    ok(['message' => 'Reply posted.', 'reply_id' => $replyId]);
}

/** POST tickets/update { id, status?, priority?, assigned_to?, department_id? } - IT and Super Admin. */
function tickets_update(mysqli $conn): void {
    require_method('POST', 'PATCH');
    $user = auth_user($conn);
    if (!tickets_can_work($user)) fail('Only IT staff and the Super Admin update tickets.', 403, 'forbidden');

    $ticket  = tickets_find($conn, $user, param_int('id'));
    $isSuper = $user['role'] === 'superadmin';

    $status = (string)param('status', $ticket['status']);
    if (!isset(TICKET_STATUS[$status])) fail('Unknown status.', 422, 'validation_error');
    // Closing belongs to the requester, through tickets/acknowledge.
    if ($status !== $ticket['status'] && !in_array($status, TICKET_IT_SET, true)) {
        fail('A ticket is closed by the person who raised it, once they acknowledge the work.', 422, 'closed_by_requester');
    }

    // Priority, assignee and department are the Super Admin's call.
    $priority = $isSuper ? (string)param('priority', $ticket['priority']) : $ticket['priority'];
    if (!isset(TICKET_PRIO[$priority])) fail('Unknown priority.', 422, 'validation_error');

    $wasAgent = (int)$ticket['assigned_to'];
    $agent    = $isSuper ? param_int('assigned_to', $wasAgent) : $wasAgent;
    $deptId   = $isSuper ? param_int('department_id', (int)$ticket['department_id']) : (int)$ticket['department_id'];

    if ($status === 'resolved' && !$agent) {
        fail('Assign the ticket to an IT person before marking it complete.', 422, 'validation_error');
    }
    if ($agent && !fetch_one($conn, "SELECT id FROM users WHERE id = ? AND role IN ('it','superadmin') LIMIT 1", 'i', [$agent])) {
        fail('Tickets can only be assigned to IT staff.', 422, 'validation_error');
    }

    $tid = (int)$ticket['id'];
    $stmt = $conn->prepare(
        "UPDATE tickets SET status = ?, priority = ?, assigned_to = ?, department_id = ?,
                assigned_at  = IF(? = 0, NULL, IF(? = ?, assigned_at, NOW())),
                completed_at = IF(? = 'resolved', COALESCE(completed_at, NOW()), NULL),
                closed_at    = NULL
          WHERE id = ?"
    );
    $agentOrNull = $agent ?: null;
    $deptOrNull  = $deptId ?: null;
    $stmt->bind_param('ssiiiiisi', $status, $priority, $agentOrNull, $deptOrNull,
                      $agent, $agent, $wasAgent, $status, $tid);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not update the ticket.', 500, 'db_error');
    }
    $stmt->close();

    $uid = (int)$user['id'];
    if ($status !== $ticket['status'])     tickets_log($conn, $tid, $uid, 'status', 'Status -> ' . TICKET_STATUS[$status]);
    if ($priority !== $ticket['priority']) tickets_log($conn, $tid, $uid, 'priority', 'Priority -> ' . TICKET_PRIO[$priority]);
    if ($agent !== $wasAgent) {
        $name = $agent ? (string)fetch_value($conn, "SELECT name FROM users WHERE id = ?", 'i', [$agent], '?') : 'nobody';
        tickets_log($conn, $tid, $uid, 'assigned', ($wasAgent ? 'Re-assigned to ' : 'Assigned to ') . $name);
    }
    if ($status === 'resolved' && $ticket['status'] !== 'resolved') {
        tickets_log($conn, $tid, $uid, 'completed', 'Marked complete - waiting for the requester to acknowledge');
    }

    ok(['message' => 'Ticket updated.', 'ticket' => shape_ticket(tickets_find($conn, $user, $tid), $user)]);
}

/** POST tickets/acknowledge { id } - the requester signs the work off and the ticket closes. */
function tickets_acknowledge(mysqli $conn): void {
    require_method('POST');
    $user   = auth_user($conn);
    $ticket = tickets_find($conn, $user, param_int('id'));

    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        fail('Only the person who raised the ticket can acknowledge it.', 403, 'forbidden');
    }
    if ($ticket['status'] !== 'resolved') {
        fail('There is nothing waiting to be acknowledged on this ticket.', 422, 'nothing_to_acknowledge');
    }

    $tid = (int)$ticket['id'];
    $conn->query("UPDATE tickets SET status = 'closed', acknowledged_at = NOW(), closed_at = NOW() WHERE id = $tid");
    tickets_log($conn, $tid, (int)$user['id'], 'acknowledged', 'Requester acknowledged the work - ticket closed');

    ok(['message' => 'Thanks - the ticket is now closed.',
        'ticket'  => shape_ticket(tickets_find($conn, $user, $tid), $user)]);
}

/** POST tickets/reraise { id, reason? } - still broken: back to the assignment queue. */
function tickets_reraise(mysqli $conn): void {
    require_method('POST');
    $user   = auth_user($conn);
    $ticket = tickets_find($conn, $user, param_int('id'));

    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        fail('Only the person who raised the ticket can send it back.', 403, 'forbidden');
    }
    if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
        fail('This ticket is already open.', 422, 'already_open');
    }

    $tid    = (int)$ticket['id'];
    $uid    = (int)$user['id'];
    $reason = (string)param('reason', '');

    $conn->query(
        "UPDATE tickets SET status = 'open', assigned_to = NULL, assigned_at = NULL,
                completed_at = NULL, acknowledged_at = NULL, closed_at = NULL,
                reopen_count = reopen_count + 1
          WHERE id = $tid"
    );
    if ($reason !== '') {
        $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,0)");
        $stmt->bind_param('iis', $tid, $uid, $reason);
        $stmt->execute();
        $stmt->close();
    }
    tickets_log($conn, $tid, $uid, 'reopened',
        'Requester marked it not resolved' . ($reason !== '' ? ' - ' . mb_substr($reason, 0, 160) : '')
        . ' - returned to the assignment queue');

    ok(['message' => 'Raised again - it is back with the Super Admin to be assigned.',
        'ticket'  => shape_ticket(tickets_find($conn, $user, $tid), $user)]);
}

/** GET tickets/locations - what the location field accepts, from the HRMS master list. */
function tickets_locations(mysqli $conn): void {
    auth_user($conn);
    $rows = fetch_all($conn, "SELECT name FROM locations ORDER BY name");
    ok(['locations' => array_column($rows, 'name'),
        'statuses'  => TICKET_STATUS,
        'priorities'=> TICKET_PRIO]);
}
