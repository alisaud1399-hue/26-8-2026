<?php
/**
 * includes/helpdesk_form_renderer.php — يرسم حقول النموذج ديناميكياً
 * يعتمد على helpdesk_form_fields schema
 */

if (!function_exists('helpdesk_render_form_field')) {
    /**
     * يرسم حقل واحد بناءً على نوعه
     *
     * @param array $field صف من helpdesk_form_fields
     * @param array $values القيم المحفوظة مسبقاً (لتعبئة الحقول)
     * @return string HTML
     */
    function helpdesk_render_form_field(array $field, array $values = []): string {
        $key = $field['field_key'];
        $value = $values[$key] ?? '';
        $id = 'fld_' . $key;
        $required = $field['is_required'] ? 'required' : '';
        $star = $field['is_required'] ? ' <span class="req">*</span>' : '';
        $placeholder = e($field['placeholder_ar'] ?? '');
        $help = $field['help_text_ar'] ?? '';

        $html = '<div class="form-row" data-field-key="' . e($key) . '" data-field-type="' . e($field['field_type']) . '">';
        $html .= '<label for="' . e($id) . '">' . e($field['field_label_ar']) . $star . '</label>';

        switch ($field['field_type']) {
            case 'text':
                $min = $field['validation_min'] ? 'minlength="' . (int)$field['validation_min'] . '"' : '';
                $max = $field['validation_max'] ? 'maxlength="' . (int)$field['validation_max'] . '"' : 'maxlength="200"';
                $pattern = $field['validation_regex'] ? 'pattern="' . e($field['validation_regex']) . '"' : '';
                $html .= '<input type="text" id="' . e($id) . '" name="fields[' . e($key) . ']" value="' . e($value) . '" ' . $required . ' ' . $min . ' ' . $max . ' ' . $pattern . ' placeholder="' . $placeholder . '">';
                break;

            case 'textarea':
                $min = $field['validation_min'] ? 'minlength="' . (int)$field['validation_min'] . '"' : '';
                $html .= '<textarea id="' . e($id) . '" name="fields[' . e($key) . ']" rows="3" ' . $required . ' ' . $min . ' placeholder="' . $placeholder . '">' . e($value) . '</textarea>';
                break;

            case 'number':
                $min = $field['validation_min'] !== null ? 'min="' . (int)$field['validation_min'] . '"' : '';
                $max = $field['validation_max'] !== null ? 'max="' . (int)$field['validation_max'] . '"' : '';
                $html .= '<input type="number" id="' . e($id) . '" name="fields[' . e($key) . ']" value="' . e($value) . '" ' . $required . ' ' . $min . ' ' . $max . ' placeholder="' . $placeholder . '">';
                break;

            case 'date':
            case 'datetime':
                $type = $field['field_type'];
                $html .= '<input type="' . e($type) . '" id="' . e($id) . '" name="fields[' . e($key) . ']" value="' . e($value) . '" ' . $required . '>';
                break;

            case 'select':
                $options = json_decode($field['options_static_json'] ?? '[]', true) ?: [];
                $html .= '<select id="' . e($id) . '" name="fields[' . e($key) . ']" ' . $required . '>';
                $html .= '<option value="">— اختر —</option>';
                foreach ($options as $k => $v) {
                    $sel = ((string)$value === (string)$k) ? 'selected' : '';
                    $html .= '<option value="' . e($k) . '" ' . $sel . '>' . e($v) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'multiselect':
                $options = json_decode($field['options_static_json'] ?? '[]', true) ?: [];
                $selected = is_array($value) ? $value : (json_decode($value, true) ?: []);
                $html .= '<div class="multi-grid" data-name="fields[' . e($key) . ']">';
                foreach ($options as $k => $v) {
                    $checked = in_array($k, $selected, true) ? 'checked' : '';
                    $html .= '<label class="multi-pill"><input type="checkbox" name="fields[' . e($key) . '][]" value="' . e($k) . '" ' . $checked . '><span>' . e($v) . '</span></label>';
                }
                $html .= '</div>';
                break;

            case 'radio':
                $options = json_decode($field['options_static_json'] ?? '[]', true) ?: [];
                $html .= '<div class="prio-pills" data-name="fields[' . e($key) . ']">';
                foreach ($options as $k => $v) {
                    $sel = ((string)$value === (string)$k) ? 'checked' : '';
                    $html .= '<label class="prio-pill"><input type="radio" name="fields[' . e($key) . ']" value="' . e($k) . '" ' . $sel . ' ' . $required . '><span>' . e($v) . '</span></label>';
                }
                $html .= '</div>';
                break;

            case 'user_picker':
            case 'asset_picker':
            case 'complaint_picker':
            case 'page_picker':
            case 'custom_picker':
                $html .= render_picker_field($field, $value);
                break;

            default:
                $html .= '<input type="text" name="fields[' . e($key) . ']" value="' . e($value) . '" ' . $required . ' placeholder="نوع غير مدعوم: ' . e($field['field_type']) . '">';
        }

        if ($help) {
            $html .= '<div class="help">' . e($help) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('render_picker_field')) {
    /**
     * يرسم picker field (user/asset/complaint/page)
     * ينفذ query مع prepared statement (آمن من SQL injection)
     */
    function render_picker_field(array $field, $current_value): string {
        $key = $field['field_key'];
        $source = $field['options_source'] ?? 'static';

        if ($source !== 'query' || empty($field['options_query'])) {
            return '<input type="text" name="fields[' . e($key) . ']" value="' . e($current_value) . '" placeholder="picker غير مكوّن">';
        }

        global $pdo;
        $query = $field['options_query'];
        $params = json_decode($field['options_query_params_json'] ?? '[]', true) ?: [];
        $val_field = $field['options_value_field'] ?? 'id';
        $lbl_field = $field['options_label_field'] ?? 'name';

        // تحضير الـ bind_values (placeholder → value)
        $bind_values = [];
        foreach ($params as $p) {
            $key_p = $p['key'] ?? '';
            $type = $p['type'] ?? 'static';
            $source_v = $p['value'] ?? null;
            if ($type === 'session' && $source_v) {
                $bind_values[':' . $key_p] = helpdesk_resolve_session_token($source_v);
            } elseif ($type === 'static') {
                $bind_values[':' . $key_p] = $source_v;
            }
        }

        try {
            $stmt = $pdo->prepare($query);
            foreach ($bind_values as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return '<div class="help" style="color:#dc2626">خطأ في تحميل الخيارات: ' . e($e->getMessage()) . '</div>';
        }

        $html = '<select name="fields[' . e($key) . ']" ' . ($field['is_required'] ? 'required' : '') . '>';
        $html .= '<option value="">— اختر —</option>';
        foreach ($rows as $r) {
            $val = (string)($r[$val_field] ?? '');
            $sel = ((string)$current_value === $val) ? 'selected' : '';
            $html .= '<option value="' . e($val) . '" ' . $sel . '>' . e($r[$lbl_field] ?? $val) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('helpdesk_resolve_session_token')) {
    /**
     * يحل session token مثل user.id, user.department_id, user.role
     */
    function helpdesk_resolve_session_token(string $token) {
        $u = current_user();
        switch ($token) {
            case 'user.id':            return (int)($u['id'] ?? 0);
            case 'user.department_id': return (int)($u['department_id'] ?? 0);
            case 'user.role':         return $u['role'] ?? 'guest';
            case 'user.username':      return $u['username'] ?? '';
            default: return null;
        }
    }
}

if (!function_exists('helpdesk_render_form')) {
    /**
     * يرسم النموذج الكامل لتصنيف (الأبناء في depends_on)
     */
    function helpdesk_render_form(PDO $pdo, int $category_id, array $values = []): string {
        $fields = helpdesk_get_form_fields($pdo, $category_id);
        if (!$fields) {
            // لا حقول مخصصة — نرجع حقل description عادي
            return '';
        }
        $html = '';
        foreach ($fields as $f) {
            $html .= helpdesk_render_form_field($f, $values);
        }
        return $html;
    }
}

if (!function_exists('helpdesk_save_form_values')) {
    /**
     * حفظ قيم الحقول بعد إنشاء التذكرة
     */
    function helpdesk_save_form_values(PDO $pdo, int $ticket_id, int $category_id, array $form_data): void {
        $fields = helpdesk_get_form_fields($pdo, $category_id);
        $by_key = [];
        foreach ($fields as $f) $by_key[$f['field_key']] = $f;

        $ins = $pdo->prepare("
            INSERT INTO helpdesk_form_values (ticket_id, field_id, value_text, value_int, value_date, value_datetime, value_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($form_data as $key => $val) {
            if (!isset($by_key[$key])) continue;
            $f = $by_key[$key];
            $val_text = null; $val_int = null; $val_date = null; $val_datetime = null; $val_json = null;

            switch ($f['field_type']) {
                case 'text':
                case 'textarea':
                case 'select':
                case 'radio':
                case 'user_picker':
                case 'asset_picker':
                case 'complaint_picker':
                case 'page_picker':
                case 'custom_picker':
                    $val_text = (string)$val;
                    break;
                case 'number':
                    $val_int = is_numeric($val) ? (int)$val : null;
                    break;
                case 'date':
                    $val_date = $val ?: null;
                    break;
                case 'datetime':
                    $val_datetime = $val ?: null;
                    break;
                case 'multiselect':
                    $val_json = json_encode((array)$val, JSON_UNESCAPED_UNICODE);
                    break;
            }

            $ins->execute([$ticket_id, (int)$f['id'], $val_text, $val_int, $val_date, $val_datetime, $val_json]);
        }
    }
}
