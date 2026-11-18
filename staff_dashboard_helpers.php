<?php
function sp_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sp_valid_date($value) {
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
        && $date->format('Y-m-d') === $value;
}

function sp_integer($value, $minimum = 0) {
    if (!is_string($value) && !is_int($value)) {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $minimum, 'max_range' => 2147483647]]);
}

function sp_post($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

function sp_progress_label($value) {
    $labels = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'];
    return $labels[$value] ?? 'Unknown';
}

function sp_next_progress($current) {
    $states = ['not_started' => ['in_progress', 'completed'], 'in_progress' => ['completed'], 'completed' => []];
    return $states[$current] ?? [];
}

function sp_transition_allowed($approval, $reservationDate, $today, $current, $target) {
    return $approval === 'approved' && $reservationDate === $today
        && in_array($target, sp_next_progress($current), true);
}

function sp_time_label($value) {
    if (!is_string($value) || !preg_match('/\A([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?\z/', $value, $m)) {
        return 'Check time';
    }
    $h = (int) $m[1];
    $min = (int) $m[2];
    $sec = isset($m[3]) ? (int) $m[3] : 0;
    if ($h > 23 || $min > 59 || $sec > 59) {
        return 'Check time';
    }
    return sprintf('%d:%02d', $h % 12 ?: 12, $min)
        . ($sec ? sprintf(':%02d', $sec) : '') . ($h >= 12 ? ' PM' : ' AM');
}

function sp_timestamp_label($utc) {
    if (!$utc) { return ''; }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
    return $date ? $date->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, g:i A') : '';
}

function sp_view_url($date, $today, $status, $court, $page = 1) {
    $params = ['status' => $status, 'court_id' => $court, 'page' => $page];
    if ($date !== $today) { $params['date'] = $date; }
    return 'staff_dashboard.php?' . http_build_query($params);
}
