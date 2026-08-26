<?php
/**
 * settings/_sections/lookup_admin.php — إدارة التصنيفات والمواقع
 *
 * جزء مستقل يُدرج داخل settings/index.php عبر:
 *   require_once __DIR__ . '/_sections/lookup_admin.php';
 *
 * يعالج الـ POST actions التالية:
 *   ?action=cat_save / cat_delete
 *   ?action=loc_save / loc_delete
 *
 * ميزات الترجمة:
 *   - render_translation_panel(): بطاقة/جدول ترجمة مع فلترة + AI + حفظ
 *   - per-row AI: settings/api/translate_one.php (Groq llama-3.3-70b)
 *   - per-row Save + Bulk AI + Bulk Save
 *
 * يعتمد على:
 *   - includes/_utils.php → display_name(), display_bilingual()
 *   - $rtl, $pdo, $uid (متغيرات globals من settings/index.php)
 *
 * @var bool   $rtl
 * @var PDO    $pdo
 * @var string $page_title (للأمان فقط، لا يُستخدم هنا)
 */

if (!defined('PMSH_SETTINGS_SECTION')) die('Direct access not allowed');

// ────────────────────────────────────────────────────────────
//  معالجة POST (CRUD + Bulk Translation)
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    // ── حفظ تصنيف (إضافة أو تعديل) ────────────────────────
    if ($action === 'cat_save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $name_en     = trim($_POST['name_en'] ?? '') ?: null;
        $parent_id   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $segment     = (int)($_POST['segment'] ?? 0) ?: null;
        $asset_type  = in_array($_POST['asset_type'] ?? '', ['medical','it','infrastructure','hvac','transport','furniture','other'])
                        ? $_POST['asset_type'] : 'other';
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) {
            flash('danger', $rtl ? 'اسم التصنيف بالعربية مطلوب' : 'Arabic name is required');
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("
                        UPDATE item_categories SET
                          name=?, name_en=?, parent_id=?, segment=?, asset_type=?,
                          sort_order=?, is_active=?
                        WHERE id=?
                    ")->execute([$name, $name_en, $parent_id, $segment, $asset_type, $sort_order, $is_active, $id]);
                    flash('success', $rtl ? '✅ تم تحديث التصنيف' : '✅ Category updated');
                } else {
                    // حساب المستوى تلقائياً من الأب
                    $level = 1;
                    if ($parent_id) {
                        $lvl = $pdo->prepare("SELECT level FROM item_categories WHERE id=?");
                        $lvl->execute([$parent_id]);
                        $level = (int)$lvl->fetchColumn() + 1;
                        if ($level > 3) {
                            flash('danger', $rtl ? 'الحد الأقصى 3 مستويات' : 'Max 3 levels');
                            header('Location:' . $_SERVER['REQUEST_URI'] . '#categories'); exit;
                        }
                    }
                    $pdo->prepare("
                        INSERT INTO item_categories
                          (name, name_en, parent_id, segment, asset_type, level, sort_order, is_active)
                        VALUES (?,?,?,?,?,?,?,?)
                    ")->execute([$name, $name_en, $parent_id, $segment, $asset_type, $level, $sort_order, $is_active]);
                    flash('success', $rtl ? '✅ تمت إضافة التصنيف' : '✅ Category added');
                }
            } catch (PDOException $e) {
                flash('danger', 'DB error: ' . $e->getMessage());
            }
        }
        header('Location:' . $_SERVER['REQUEST_URI'] . '#categories'); exit;
    }

    // ── حذف تصنيف ─────────────────────────────────────────
    elseif ($action === 'cat_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                // التحقق من وجود أبناء أو أصول مرتبطة
                $kids = (int)$pdo->prepare("SELECT COUNT(*) FROM item_categories WHERE parent_id=?");
                $kids->execute([$id]); $kids = $kids->fetchColumn();
                $used = (int)$pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id=?");
                $used->execute([$id]); $used = $used->fetchColumn();
                if ($kids > 0) {
                    flash('danger', $rtl ? "لا يمكن الحذف: في {$kids} تصنيف فرعي" : "Cannot delete: has {$kids} children");
                } elseif ($used > 0) {
                    flash('warning', $rtl ? "لا يمكن الحذف: مرتبط بـ {$used} أصل" : "Cannot delete: used by {$used} assets");
                } else {
                    $pdo->prepare("DELETE FROM item_categories WHERE id=?")->execute([$id]);
                    flash('success', $rtl ? '✅ تم الحذف' : '✅ Deleted');
                }
            } catch (PDOException $e) {
                flash('danger', 'DB error: ' . $e->getMessage());
            }
        }
        header('Location:' . $_SERVER['REQUEST_URI'] . '#categories'); exit;
    }

    // ── حفظ موقع ─────────────────────────────────────────
    elseif ($action === 'loc_save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $name_en     = trim($_POST['name_en'] ?? '') ?: null;
        $parent_id   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $location_type = in_array($_POST['location_type'] ?? '', ['building','floor','room'])
                        ? $_POST['location_type'] : 'room';
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) {
            flash('danger', $rtl ? 'اسم الموقع بالإنجليزية مطلوب' : 'English name is required');
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("
                        UPDATE item_locations SET
                          name=?, name_en=?, parent_id=?, location_type=?, is_active=?
                        WHERE id=?
                    ")->execute([$name, $name_en, $parent_id, $location_type, $is_active, $id]);
                    flash('success', $rtl ? '✅ تم تحديث الموقع' : '✅ Location updated');
                } else {
                    $pdo->prepare("
                        INSERT INTO item_locations
                          (name, name_en, parent_id, location_type, is_active)
                        VALUES (?,?,?,?,?)
                    ")->execute([$name, $name_en, $parent_id, $location_type, $is_active]);
                    flash('success', $rtl ? '✅ تمت إضافة الموقع' : '✅ Location added');
                }
            } catch (PDOException $e) {
                flash('danger', 'DB error: ' . $e->getMessage());
            }
        }
        header('Location:' . $_SERVER['REQUEST_URI'] . '#locations'); exit;
    }

    // ── حذف موقع ─────────────────────────────────────────
    elseif ($action === 'loc_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $kids = (int)$pdo->prepare("SELECT COUNT(*) FROM item_locations WHERE parent_id=?");
                $kids->execute([$id]); $kids = $kids->fetchColumn();
                $used = (int)$pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id=?");
                $used->execute([$id]); $used = $used->fetchColumn();
                if ($kids > 0) {
                    flash('danger', $rtl ? "لا يمكن الحذف: في {$kids} موقع فرعي" : "Cannot delete: has {$kids} children");
                } elseif ($used > 0) {
                    flash('warning', $rtl ? "لا يمكن الحذف: مرتبط بـ {$used} أصل" : "Cannot delete: used by {$used} assets");
                } else {
                    $pdo->prepare("DELETE FROM item_locations WHERE id=?")->execute([$id]);
                    flash('success', $rtl ? '✅ تم الحذف' : '✅ Deleted');
                }
            } catch (PDOException $e) {
                flash('danger', 'DB error: ' . $e->getMessage());
            }
        }
        header('Location:' . $_SERVER['REQUEST_URI'] . '#locations'); exit;
    }
}

// ────────────────────────────────────────────────────────────
//  جلب البيانات
// ────────────────────────────────────────────────────────────
$cats_all = $pdo->query("SELECT * FROM item_categories ORDER BY level, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$cats_l1 = array_values(array_filter($cats_all, fn($c) => $c['level'] == 1));
$cats_missing_en = array_values(array_filter($cats_all, fn($c) => empty($c['name_en'])));
$cats_total_missing = count($cats_missing_en);

$locs_all = $pdo->query("SELECT * FROM item_locations ORDER BY location_type, parent_id, name")->fetchAll(PDO::FETCH_ASSOC);
$buildings = array_values(array_filter($locs_all, fn($l) => $l['location_type'] == 'building'));
$locs_missing_en = array_values(array_filter($locs_all, fn($l) => empty($l['name_en'])));
$locs_total_missing = count($locs_missing_en);

// ══════════════════════════════════════════════════════════════════
//  مُعالج فلاتر الترجمة الجديد (بطاقات)
// ══════════════════════════════════════════════════════════════════
function get_translation_rows(PDO $pdo, string $tbl, array $filter): array {
    $where = ['1=1'];
    $params = [];
    if ($filter['q'] !== '') {
        $where[] = '(name LIKE ? OR name_en LIKE ?)';
        $like = '%' . $filter['q'] . '%';
        $params = array_merge($params, [$like, $like]);
    }
    if ($filter['status'] === 'missing') {
        $where[] = "(name_en IS NULL OR name_en='')";
    } elseif ($filter['status'] === 'done') {
        $where[] = "(name_en IS NOT NULL AND name_en <> '')";
    }
    if ($tbl === 'item_categories' && $filter['level']) {
        $where[] = 'level = ?';
        $params[] = (int)$filter['level'];
    }
    if ($tbl === 'item_categories' && $filter['asset_type']) {
        $where[] = 'asset_type = ?';
        $params[] = $filter['asset_type'];
    }
    if ($tbl === 'item_locations' && $filter['loc_type']) {
        $where[] = 'location_type = ?';
        $params[] = $filter['loc_type'];
    }
    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

function get_translation_count(PDO $pdo, string $tbl, array $filter): int {
    $w = get_translation_rows($pdo, $tbl, $filter);
    $st = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE {$w['sql']}");
    $st->execute($w['params']);
    return (int)$st->fetchColumn();
}

function fetch_translation_cards(PDO $pdo, string $tbl, array $filter, int $page, int $per = 15): array {
    $w = get_translation_rows($pdo, $tbl, $filter);
    $offset = max(0, ($page - 1) * $per);
    $sql = "SELECT * FROM $tbl WHERE {$w['sql']} ORDER BY id LIMIT $per OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($w['params']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

$asset_types_map = [
    'medical'        => $rtl ? 'طبي' : 'Medical',
    'it'             => $rtl ? 'تقنية معلومات' : 'IT',
    'infrastructure' => $rtl ? 'بنية تحتية' : 'Infrastructure',
    'hvac'           => $rtl ? 'تكييف' : 'HVAC',
    'transport'      => $rtl ? 'مركبات' : 'Transport',
    'furniture'      => $rtl ? 'أثاث' : 'Furniture',
    'other'          => $rtl ? 'أخرى' : 'Other',
];

// ══════════════════════════════════════════════════════════════════
//  مُكوّن بطاقة الترجمة (يُستخدم للتصنيفات والمواقع)
// ══════════════════════════════════════════════════════════════════
function render_translation_panel(
    PDO $pdo, bool $rtl, string $tbl, string $title_ar, string $title_en, string $icon, string $anchor,
    string $source_label_ar, string $source_label_en, string $target_label_ar, string $target_label_en
): void {
    $filter = [
        'q'         => trim($_GET[$anchor.'_q'] ?? ''),
        'status'    => $_GET[$anchor.'_st'] ?? 'missing',
        'level'     => $tbl === 'item_categories' ? ($_GET[$anchor.'_lv'] ?? '') : '',
        'asset_type'=> $tbl === 'item_categories' ? ($_GET[$anchor.'_at'] ?? '') : '',
        'loc_type'  => $tbl === 'item_locations'  ? ($_GET[$anchor.'_lt'] ?? '') : '',
    ];

    $total_filtered = get_translation_count($pdo, $tbl, $filter);
    $page = max(1, (int)($_GET[$anchor.'_page'] ?? 1));
    $rows = fetch_translation_cards($pdo, $tbl, $filter, $page, 15);
    $total_pages = max(1, (int)ceil($total_filtered / 15));
    ?>
    <div class="s-card tr-panel" id="<?= $anchor ?>" style="border-left:3px solid #f59e0b;padding:0;overflow:hidden;margin-bottom:14px">
      <!-- عنوان بسيط فقط -->
      <div style="background:#fef3c7;padding:10px 14px;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <i class="fa-solid <?= $icon ?>" style="color:#f59e0b"></i>
        <strong style="font-size:13.5px;font-weight:900;color:#78350f"><?= $rtl ? $title_ar : $title_en ?></strong>
        <span class="eng" style="font-size:11.5px;color:#92400e;font-weight:800;margin-inline-start:auto">
          <?= number_format($total_filtered) ?> <?= $rtl ? 'نتيجة' : 'results' ?>
        </span>
      </div>

      <!-- فلاتر مضغوطة -->
      <form method="GET" style="padding:8px 14px;background:#fff;border-bottom:1px solid #fde68a;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="text" name="<?= $anchor ?>_q" value="<?= e($filter['q']) ?>" placeholder="<?= $rtl ? '🔍 بحث...' : '🔍 Search...' ?>"
               style="flex:1;min-width:140px;height:32px;padding:0 10px;border:1px solid #fde68a;border-radius:7px;font-family:'Tajawal';font-size:12.5px;outline:none;background:#fffbeb">

        <select name="<?= $anchor ?>_st" onchange="this.form.submit()" style="height:32px;padding:0 8px;border:1px solid #fde68a;border-radius:7px;font-family:'Tajawal';font-size:12px;background:#fff">
          <option value="missing" <?= $filter['status']==='missing'?'selected':'' ?>><?= $rtl ? '⏳ بانتظار' : '⏳ Pending' ?></option>
          <option value="done"   <?= $filter['status']==='done'?'selected':'' ?>><?= $rtl ? '✓ مترجم' : '✓ Done' ?></option>
          <option value=""       <?= $filter['status']===''?'selected':'' ?>><?= $rtl ? '📋 الكل' : '📋 All' ?></option>
        </select>

        <?php if ($tbl === 'item_categories'): ?>
          <select name="<?= $anchor ?>_lv" onchange="this.form.submit()" style="height:32px;padding:0 8px;border:1px solid #fde68a;border-radius:7px;font-family:'Tajawal';font-size:12px;background:#fff">
            <option value=""><?= $rtl ? 'كل المستويات' : 'All Levels' ?></option>
            <option value="1" <?= $filter['level']==='1'?'selected':'' ?>>L1</option>
            <option value="2" <?= $filter['level']==='2'?'selected':'' ?>>L2</option>
            <option value="3" <?= $filter['level']==='3'?'selected':'' ?>>L3</option>
          </select>
        <?php else: ?>
          <select name="<?= $anchor ?>_lt" onchange="this.form.submit()" style="height:32px;padding:0 8px;border:1px solid #fde68a;border-radius:7px;font-family:'Tajawal';font-size:12px;background:#fff">
            <option value=""><?= $rtl ? 'كل الأنواع' : 'All Types' ?></option>
            <option value="building" <?= $filter['loc_type']==='building'?'selected':'' ?>><?= $rtl ? '🏢 مبنى' : '🏢 Building' ?></option>
            <option value="floor"    <?= $filter['loc_type']==='floor'?'selected':'' ?>><?= $rtl ? '⬆ طابق' : '⬆ Floor' ?></option>
            <option value="room"     <?= $filter['loc_type']==='room'?'selected':'' ?>><?= $rtl ? '🚪 غرفة' : '🚪 Room' ?></option>
          </select>
        <?php endif; ?>

        <button type="submit" style="height:32px;padding:0 12px;background:#2563eb;color:#fff;border:none;border-radius:7px;font-family:'Tajawal';font-size:12px;font-weight:800;cursor:pointer">
          <i class="fa-solid fa-filter"></i>
        </button>
        <?php if ($filter['q'] || $filter['status']==='done' || $filter['level'] || $filter['loc_type']): ?>
          <a href="?#<?= $anchor ?>" style="height:32px;padding:0 10px;background:#fff;border:1px solid #fde68a;border-radius:7px;color:#92400e;font-family:'Tajawal';font-size:12px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center">
            <i class="fa-solid fa-xmark"></i>
          </a>
        <?php endif; ?>
      </form>

      <!-- جدول بسيط ومدمج -->
      <?php if (!$rows): ?>
        <div style="padding:40px 20px;text-align:center;color:#10b981;font-weight:700">
          <i class="fa-solid fa-circle-check" style="font-size:36px;margin-bottom:8px;display:block"></i>
          <?= $rtl ? '✅ لا توجد عناصر بهذه الفلترة — كل شي تمام!' : '✅ No items for this filter' ?>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto;background:#fff">
      <table class="tr-table">
        <thead>
          <tr>
            <th style="width:30px"><input type="checkbox" class="tr-check-all" data-anchor="<?= $anchor ?>" onchange="trToggleAll(this)"></th>
            <th style="width:32%"><?= $rtl ? $source_label_ar : $source_label_en ?></th>
            <th style="width:6%"><?= $rtl ? 'النوع' : 'Type' ?></th>
            <th><?= $rtl ? $target_label_ar : $target_label_en ?></th>
            <th style="width:90px"><?= $rtl ? 'إجراء' : 'Action' ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
          $is_done = !empty($row['name_en']);
          $row_class = $is_done ? 'done' : 'pending';
          $source = $row['name'];
          $current = $row['name_en'] ?? '';
          if ($tbl === 'item_categories') {
            $type_lbl = '<span class="lvl-badge lvl-'.(int)$row['level'].'">L'.(int)$row['level'].'</span>';
          } else {
            $type_lbl = '<span class="loc-badge loc-'.e($row['location_type']).'">'.e($row['location_type']).'</span>';
          }
        ?>
        <tr class="tr-row <?= $row_class ?>" id="trRow<?= $anchor ?>_<?= (int)$row['id'] ?>">
          <td><input type="checkbox" class="tr-check" value="<?= (int)$row['id'] ?>" data-anchor="<?= $anchor ?>"></td>
          <td dir="<?= $tbl === 'item_categories' ? 'rtl' : 'ltr' ?>" style="font-weight:700;color:#0f172a;font-size:13px"><?= e($source) ?></td>
          <td><?= $type_lbl ?></td>
          <td>
            <input type="text"
                   class="tr-input tr-input-inline"
                   id="trInput<?= $anchor ?>_<?= (int)$row['id'] ?>"
                   value="<?= e($current) ?>"
                   placeholder="<?= $rtl ? 'ترجم...' : 'translate...' ?>"
                   dir="<?= $tbl === 'item_categories' ? 'ltr' : 'rtl' ?>">
          </td>
          <td>
            <div style="display:flex;gap:3px">
              <button type="button" class="tr-btn tr-btn-ai-sm" onclick="aiSuggest('<?= $tbl ?>', <?= (int)$row['id'] ?>, '<?= $anchor ?>')" title="<?= $rtl ? 'ترجمة AI' : 'AI Translate' ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
              </button>
              <button type="button" class="tr-btn tr-btn-save-sm" onclick="saveTranslation('<?= $tbl ?>', <?= (int)$row['id'] ?>, '<?= $anchor ?>')">
                <i class="fa-solid fa-floppy-disk"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Bulk action bar -->
      <div class="tr-bulk-bar">
        <span class="tr-bulk-count" id="trCount<?= $anchor ?>">0</span>
        <span style="font-size:12px;color:#475569"><?= $rtl ? 'محدد' : 'selected' ?></span>
        <button type="button" class="tr-bulk-btn tr-bulk-ai" onclick="bulkAi('<?= $tbl ?>', '<?= $anchor ?>')">
          <i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl ? 'ترجمة المحدد' : 'AI Translate Selected' ?>
        </button>
        <button type="button" class="tr-bulk-btn tr-bulk-save" onclick="bulkSave('<?= $tbl ?>', '<?= $anchor ?>')">
          <i class="fa-solid fa-floppy-disk"></i> <?= $rtl ? 'حفظ المحدد' : 'Save Selected' ?>
        </button>
      </div>
      </div>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($total_pages > 1):
        $qs = function($p) use ($anchor) {
            $q = $_GET;
            unset($q['#']);
            $q[$anchor.'_page'] = $p;
            return '?' . http_build_query($q) . '#' . $anchor;
        };
        $prev = max(1, $page - 1);
        $next = min($total_pages, $page + 1);
      ?>
      <div style="padding:14px 18px;border-top:1px solid #fde68a;display:flex;justify-content:center;gap:6px;align-items:center;background:#fffbeb">
        <a class="tr-page <?= $page<=1?'disabled':'' ?>" href="<?= $qs($prev) ?>"><i class="fa-solid fa-chevron-<?= $rtl?'right':'left' ?>"></i></a>
        <span class="tr-page current eng" dir="ltr"><?= $page ?> / <?= $total_pages ?></span>
        <a class="tr-page <?= $page>=$total_pages?'disabled':'' ?>" href="<?= $qs($next) ?>"><i class="fa-solid fa-chevron-<?= $rtl?'left':'right' ?>"></i></a>
      </div>
      <?php endif; ?>
    </div>
    <?php
}
?>

<!-- ─── قسم إدارة التصنيفات ───────────────────────────── -->
<div class="s-card" id="categories">
    <div class="s-card-head">
        <div class="s-card-title">
            <i class="fa-solid fa-folder-tree"></i>
            <?= $rtl ? 'إدارة التصنيفات' : 'Category Management' ?>
            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:10.5px;margin-right:8px">
                ⚠ <?= $cats_total_missing ?> <?= $rtl ? 'بدون ترجمة إنجليزية' : 'missing EN' ?>
            </span>
        </div>
        <div style="display:flex;gap:6px">
            <button type="button" class="s-btn s-btn-outline" onclick="scrollToAnchor('categories-bulk')">
                <i class="fa-solid fa-language"></i> <?= $rtl ? 'ترجمة جماعية' : 'Bulk Translate' ?>
            </button>
            <button type="button" class="s-btn s-btn-primary" onclick="openCatModal(0, null, 1)">
                <i class="fa-solid fa-plus"></i> <?= $rtl ? 'تصنيف جذر' : 'Root Category' ?>
            </button>
        </div>
    </div>

    <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:11.5px;color:#64748b">
        <i class="fa-solid fa-info-circle"></i>
        <?= $rtl
            ? 'التصنيفات 3-مستويات (L1 / L2 / L3). كل تصنيف له اسم عربي وإنجليزي ونوع أصل. استخدم الفلاتر أدناه للبحث والترجمة الجماعية.'
            : '3-level categories (L1 / L2 / L3). Each has Arabic & English names + asset type. Use filters below to search & bulk-translate.' ?>
    </div>

</div>

<!-- ─── Bulk Translation Helper (تصنيفات) ──────────────── -->
<?php
render_translation_panel(
    $pdo, $rtl,
    'item_categories',
    'ترجمة التصنيفات', 'Translate Categories',
    'fa-folder-tree',
    'categories-bulk',
    'الاسم بالعربية (الحالي)', 'Current Arabic Name',
    'الاسم بالإنجليزية', 'English Name'
);
?>

<!-- ─── قسم إدارة المواقع ──────────────────────────────── -->
<div class="s-card" id="locations">
    <div class="s-card-head">
        <div class="s-card-title">
            <i class="fa-solid fa-location-dot"></i>
            <?= $rtl ? 'إدارة المواقع' : 'Location Management' ?>
            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:10.5px;margin-right:8px">
                ⚠ <?= $locs_total_missing ?> <?= $rtl ? 'بدون ترجمة عربية' : 'missing AR' ?>
            </span>
        </div>
        <div style="display:flex;gap:6px">
            <button type="button" class="s-btn s-btn-outline" onclick="scrollToAnchor('locations-bulk')">
                <i class="fa-solid fa-language"></i> ترجمة جماعية
            </button>
            <button type="button" class="s-btn s-btn-primary" onclick="openLocModal(0, null, 'building')">
                <i class="fa-solid fa-plus"></i> <?= $rtl ? 'مبنى جديد' : 'New Building' ?>
            </button>
        </div>
    </div>

    <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:11.5px;color:#64748b">
        <i class="fa-solid fa-info-circle"></i>
        <?= $rtl
            ? 'شجرة المواقع: مبنى → طابق → غرفة. المباني فقط هي الجذر (لا يوجد أب). كل موقع يدعم اسمين: إنجليزي (أساسي) وعربي.'
            : 'Location tree: Building → Floor → Room. Buildings are root level. Each supports English (primary) and Arabic names.' ?>
    </div>

</div>

<!-- ─── Bulk Translation Helper (مواقع) ────────────────── -->
<?php
render_translation_panel(
    $pdo, $rtl,
    'item_locations',
    'ترجمة المواقع', 'Translate Locations',
    'fa-location-dot',
    'locations-bulk',
    'الاسم بالإنجليزية (الحالي)', 'Current English Name',
    'الاسم بالعربية', 'Arabic Name'
);
?>

<!-- ─── Modal: تحرير/إضافة تصنيف ─────────────────────── -->
<div id="catModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:2000;align-items:center;justify-content:center;padding:20px">
    <div style="background:#fff;border-radius:18px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25)">
        <div style="padding:18px 22px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
            <h3 id="catModalTitle" style="margin:0;font-size:15px;font-weight:900">إضافة تصنيف</h3>
            <button type="button" onclick="closeCatModal()" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8">×</button>
        </div>
        <form method="POST" style="padding:22px">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="cat_save">
            <input type="hidden" name="id" id="catId" value="0">
            <input type="hidden" name="parent_id" id="catParentId" value="">
            <input type="hidden" name="level" id="catLevel" value="1">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div class="fg full">
                    <label>الاسم بالعربية *</label>
                    <input type="text" name="name" id="catName" class="mfi" required maxlength="150">
                </div>
                <div class="fg full">
                    <label>الاسم بالإنجليزية</label>
                    <div style="display:flex;gap:6px">
                        <input type="text" name="name_en" id="catNameEn" class="mfi" maxlength="150" dir="ltr" style="flex:1">
                        <button type="button" onclick="suggestTranslation('catName','catNameEn','category','en')" class="s-btn s-btn-outline" title="<?= $rtl ? 'اقتراح ترجمة عبر Groq AI' : 'Suggest via Groq AI' ?>" id="catSuggestBtn" style="flex-shrink:0">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> AI
                        </button>
                    </div>
                </div>
                <div class="fg">
                    <label>الرقم التكويدي (segment)</label>
                    <input type="number" name="segment" id="catSegment" class="mfi" min="0" max="9999">
                </div>
                <div class="fg">
                    <label>نوع الأصل</label>
                    <select name="asset_type" id="catAssetType" class="mfi">
                        <?php foreach ($asset_types_map as $k=>$l): ?>
                        <option value="<?= $k ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>ترتيب العرض</label>
                    <input type="number" name="sort_order" id="catSort" class="mfi" value="0">
                </div>
                <div class="fg" style="flex-direction:row;align-items:center;gap:8px;margin-top:24px">
                    <input type="checkbox" name="is_active" id="catActive" checked value="1">
                    <label for="catActive" style="margin:0">مفعّل</label>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
                <button type="button" onclick="closeCatModal()" class="s-btn s-btn-outline">إلغاء</button>
                <button type="submit" class="s-btn s-btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal: تحرير/إضافة موقع ───────────────────────── -->
<div id="locModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:2000;align-items:center;justify-content:center;padding:20px">
    <div style="background:#fff;border-radius:18px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25)">
        <div style="padding:18px 22px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
            <h3 id="locModalTitle" style="margin:0;font-size:15px;font-weight:900">إضافة موقع</h3>
            <button type="button" onclick="closeLocModal()" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8">×</button>
        </div>
        <form method="POST" style="padding:22px">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="loc_save">
            <input type="hidden" name="id" id="locId" value="0">
            <input type="hidden" name="parent_id" id="locParentId" value="">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div class="fg full">
                    <label>الاسم بالإنجليزية *</label>
                    <input type="text" name="name" id="locName" class="mfi" required maxlength="200" dir="ltr">
                </div>
                <div class="fg full">
                    <label>الاسم بالعربية</label>
                    <div style="display:flex;gap:6px">
                        <input type="text" name="name_en" id="locNameAr" class="mfi" maxlength="200" style="flex:1">
                        <button type="button" onclick="suggestTranslation('locName','locNameAr','location','ar')" class="s-btn s-btn-outline" title="<?= $rtl ? 'اقتراح ترجمة عبر Groq AI' : 'Suggest via Groq AI' ?>" id="locSuggestBtn" style="flex-shrink:0">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> AI
                        </button>
                    </div>
                </div>
                <div class="fg">
                    <label>نوع الموقع</label>
                    <select name="location_type" id="locType" class="mfi">
                        <option value="building">building (مبنى)</option>
                        <option value="floor">floor (طابق)</option>
                        <option value="room">room (غرفة)</option>
                    </select>
                </div>
                <div class="fg" style="flex-direction:row;align-items:center;gap:8px;margin-top:24px">
                    <input type="checkbox" name="is_active" id="locActive" checked value="1">
                    <label for="locActive" style="margin:0">مفعّل</label>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
                <button type="button" onclick="closeLocModal()" class="s-btn s-btn-outline">إلغاء</button>
                <button type="submit" class="s-btn s-btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Buttons ── */
.s-btn { background:#fff; border:1.5px solid #e2e8f0; color:#475569; padding:7px 14px; border-radius:9px; font-family:'Tajawal'; font-size:12.5px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:.15s; text-decoration:none; }
.s-btn:hover { background:#f8fafc; border-color:#cbd5e1; }
.s-btn-sm { padding:5px 9px; font-size:11px; }
.s-btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border-color:#2563eb; }
.s-btn-primary:hover { background:linear-gradient(135deg,#1d4ed8,#1e40af); color:#fff; }
.s-btn-outline { background:transparent; }
.s-btn-danger { background:#fee2e2; border-color:#fecaca; color:#dc2626; }
.s-btn-danger:hover { background:#dc2626; color:#fff; border-color:#dc2626; }

/* ── Tree rows ── */
.cat-row, .loc-row { display:flex; align-items:center; gap:10px; padding:9px 14px; border-bottom:1px solid #f8fafc; transition:.15s; }
.cat-row:hover, .loc-row:hover { background:#f8fafc; }
.cat-children { margin-right:0; }
.cat-depth-0 { font-weight:700; }
.cat-depth-1 { background:#fafcff; }
.cat-depth-2 { background:#f4f8ff; padding-right:48px !important; }
.cat-depth-3 { background:#eef4ff; padding-right:72px !important; }
.tree-toggle { background:none; border:none; cursor:pointer; color:#94a3b8; width:24px; height:24px; }
.tree-toggle:hover { color:#2563eb; }
.lvl-badge { padding:2px 8px; border-radius:99px; font-size:10px; font-weight:900; font-family:'Inter'; flex-shrink:0; }
.lvl-1 { background:#dbeafe; color:#1e40af; }
.lvl-2 { background:#fef3c7; color:#92400e; }
.lvl-3 { background:#ede9fe; color:#6d28d9; }
.loc-badge { padding:2px 8px; border-radius:99px; font-size:10px; font-weight:900; flex-shrink:0; }
.loc-building { background:#dbeafe; color:#1e40af; }
.loc-floor { background:#fef3c7; color:#92400e; }
.loc-room { background:#f1f5f9; color:#475569; }
.mfi { height:42px; padding:0 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-family:'Tajawal'; font-size:13.5px; outline:none; transition:.2s; color:#0f172a; background:#fff; width:100%; box-sizing:border-box; }
.mfi:focus { border-color:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.1); }
.fg { display:flex; flex-direction:column; gap:6px; }

/* ── Translation Table (مضغوط — 15 صف لكل صفحة) ── */
.tr-panel { margin-bottom:14px; }
.tr-table { width:100%; border-collapse:collapse; }
.tr-table thead { background:#fef3c7; }
.tr-table th { padding:7px 10px; text-align:right; font-size:11px; font-weight:900; color:#78350f; border-bottom:1.5px solid #fde68a; text-transform:uppercase; letter-spacing:.3px; }
.tr-table th:first-child, .tr-table td:first-child { padding-left:10px; padding-right:10px; text-align:center; width:30px; }
.tr-table td { padding:5px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.tr-row.pending td { background:#fff; }
.tr-row.done td { background:#f0fdf4; }
.tr-row:hover td { background:#fef9c3; }
.tr-row.done:hover td { background:#dcfce7; }
.tr-input-inline { height:28px; padding:0 8px; border:1px solid #e2e8f0; border-radius:6px; font-size:12.5px; outline:none; width:100%; box-sizing:border-box; font-family:'Tajawal'; background:#fff; transition:.15s; }
.tr-input-inline:focus { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.15); }
.tr-row.done .tr-input-inline { border-color:#86efac; background:#fff; }
.tr-row.pending .tr-input-inline { border-color:#fde68a; }
.tr-btn-sm { padding:5px 8px; border:none; border-radius:6px; cursor:pointer; font-size:11px; transition:.15s; display:inline-flex; align-items:center; justify-content:center; }
.tr-btn-sm:hover { transform:translateY(-1px); }
.tr-btn-ai-sm { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.tr-btn-ai-sm:hover { background:#fde68a; }
.tr-btn-save-sm { background:#2563eb; color:#fff; }
.tr-btn-save-sm:hover { background:#1d4ed8; }
.tr-btn-save-sm:disabled { opacity:.5; pointer-events:none; }
.tr-check, .tr-check-all { width:16px; height:16px; cursor:pointer; accent-color:#2563eb; }
.tr-bulk-bar { background:#fef3c7; border-top:1.5px solid #fde68a; padding:10px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.tr-bulk-count { font-family:'Inter'; font-size:13px; font-weight:900; color:#92400e; background:#fff; padding:3px 10px; border-radius:99px; min-width:32px; text-align:center; }
.tr-bulk-btn { border:none; border-radius:8px; padding:7px 14px; font-family:'Tajawal'; font-size:12.5px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:.15s; }
.tr-bulk-btn:hover { transform:translateY(-1px); }
.tr-bulk-btn:disabled { opacity:.4; pointer-events:none; }
.tr-bulk-ai { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; }
.tr-bulk-save { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; }
.tr-page { padding:6px 11px; border-radius:7px; background:#fff; border:1px solid #fde68a; color:#92400e; text-decoration:none; font-size:12px; font-weight:800; }
.tr-page.current { background:#92400e; color:#fff; border-color:#92400e; font-family:'Inter'; }
.tr-page.disabled { opacity:.4; pointer-events:none; }
.fg label { font-size:12px; font-weight:800; color:#475569; }
</style>

<script>
// RTL already defined in parent index.php as var RTL
const ASSET_TYPES = <?= json_encode($asset_types_map, JSON_UNESCAPED_UNICODE) ?>;

function scrollToAnchor(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
}

function toggleCatChildren(id) {
    const child = document.querySelector('.cat-children-of-' + id);
    const btn = document.querySelector('.tree-toggle[data-id="' + id + '"] i');
    if (!child) return;
    const isHidden = child.style.display === 'none';
    child.style.display = isHidden ? '' : 'none';
    if (btn) btn.className = isHidden ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-left';
}

// ── Category Modal ──
function openCatModal(id, parentId, level) {
    document.getElementById('catId').value = id || 0;
    document.getElementById('catParentId').value = parentId || '';
    document.getElementById('catLevel').value = level || 1;
    document.getElementById('catModalTitle').textContent = id ? 'تعديل تصنيف' : (parentId ? 'إضافة تصنيف فرعي' : 'إضافة تصنيف جذر');
    document.getElementById('catName').value = '';
    document.getElementById('catNameEn').value = '';
    document.getElementById('catSegment').value = '';
    document.getElementById('catAssetType').value = 'other';
    document.getElementById('catSort').value = '0';
    document.getElementById('catActive').checked = true;
    document.getElementById('catModal').style.display = 'flex';
}
function closeCatModal() { document.getElementById('catModal').style.display = 'none'; }
function editCat(c) {
    document.getElementById('catId').value = c.id;
    document.getElementById('catParentId').value = c.parent_id || '';
    document.getElementById('catLevel').value = c.level;
    document.getElementById('catModalTitle').textContent = 'تعديل: ' + c.name;
    document.getElementById('catName').value = c.name || '';
    document.getElementById('catNameEn').value = c.name_en || '';
    document.getElementById('catSegment').value = c.segment || '';
    document.getElementById('catAssetType').value = c.asset_type || 'other';
    document.getElementById('catSort').value = c.sort_order || 0;
    document.getElementById('catActive').checked = c.is_active == 1;
    document.getElementById('catModal').style.display = 'flex';
}

// ── Location Modal ──
function openLocModal(id, parentId, type) {
    document.getElementById('locId').value = id || 0;
    document.getElementById('locParentId').value = parentId || '';
    document.getElementById('locType').value = type || 'building';
    document.getElementById('locModalTitle').textContent = id ? 'تعديل موقع' : 'إضافة موقع';
    document.getElementById('locName').value = '';
    document.getElementById('locNameAr').value = '';
    document.getElementById('locType').disabled = !!id;
    document.getElementById('locActive').checked = true;
    document.getElementById('locModal').style.display = 'flex';
}
function closeLocModal() { document.getElementById('locModal').style.display = 'none'; }
function editLoc(l) {
    document.getElementById('locId').value = l.id;
    document.getElementById('locParentId').value = l.parent_id || '';
    document.getElementById('locModalTitle').textContent = 'تعديل: ' + l.name;
    document.getElementById('locName').value = l.name || '';
    document.getElementById('locNameAr').value = l.name_en || '';
    document.getElementById('locType').value = l.location_type || 'room';
    document.getElementById('locType').disabled = true; // لا نغيّر النوع بعد الإنشاء
    document.getElementById('locActive').checked = l.is_active == 1;
    document.getElementById('locModal').style.display = 'flex';
}

// إغلاق عند الضغط خارج المودال
['catModal','locModal'].forEach(function(id){
    document.getElementById(id).addEventListener('click', function(e){
        if (e.target.id === id) { window['close' + id.charAt(0).toUpperCase() + id.slice(1)](); }
    });
});

// ── اقتراح ترجمة عبر Groq ───────────────────────────────────
async function suggestTranslation(srcId, tgtId, ctx, targetLang) {
    const srcEl = document.getElementById(srcId);
    const tgtEl = document.getElementById(tgtId);
    const btnId = srcId === 'catName' ? 'catSuggestBtn' : 'locSuggestBtn';
    const btn = document.getElementById(btnId);
    if (!srcEl || !tgtEl || !btn) return;

    const text = (srcEl.value || '').trim();
    if (!text) { srcEl.focus(); alert(RTL ? 'أدخل الاسم أولاً' : 'Enter name first'); return; }

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('text', text);
        fd.append('target', targetLang);
        fd.append('context', ctx);
        const r = await fetch('api/suggest_translation.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok && d.suggestion) {
            tgtEl.value = d.suggestion;
            tgtEl.style.background = '#d1fae5';
            setTimeout(() => tgtEl.style.background = '', 1200);
        } else {
            alert('⚠ ' + (d.msg || 'Failed'));
        }
    } catch (e) {
        alert('Network error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// تشغيل عدّاد عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('catCount')) {
        const n = document.querySelectorAll('.cat-row').length;
        document.getElementById('catCount').textContent = n + ' ' + (RTL?'تصنيف':'items');
    }
    if (document.getElementById('locCount')) {
        const n = document.querySelectorAll('.loc-row').length;
        document.getElementById('locCount').textContent = n + ' ' + (RTL?'موقع':'items');
    }
});

// ── تحديد/إلغاء تحديد كل الصفوف في جدول معيّن ─────────────
function trToggleAll(masterCb) {
  const anchor = masterCb.dataset.anchor;
  document.querySelectorAll(`input.tr-check[data-anchor="${anchor}"]`).forEach(cb => {
    cb.checked = masterCb.checked;
  });
  trUpdateCount(anchor);
}

function trUpdateCount(anchor) {
  const checks = document.querySelectorAll(`input.tr-check[data-anchor="${anchor}"]`);
  const n = Array.from(checks).filter(c => c.checked).length;
  const el = document.getElementById('trCount' + anchor);
  if (el) el.textContent = n;
  const master = document.querySelector(`input.tr-check-all[data-anchor="${anchor}"]`);
  if (master && checks.length) master.checked = (n === checks.length);
}

// اجمع التحديد في الجدول → راقب التغيير على أي checkbox
document.addEventListener('change', e => {
  if (e.target.classList.contains('tr-check')) {
    trUpdateCount(e.target.dataset.anchor);
  }
});

// ── ترجمة جماعية للصفوف المحددة (Bulk AI) ─────────────────
async function bulkAi(tbl, anchor) {
  const checks = Array.from(document.querySelectorAll(`input.tr-check[data-anchor="${anchor}"]:checked`));
  if (!checks.length) { alert(RTL ? 'حدد صف واحد على الأقل' : 'Select at least one row'); return; }
  if (!confirm((RTL?`هل تترجم ${checks.length} صف بالـ AI؟ سيتم ملء الحقول فقط (تحفظ لاحقاً).`
                       :`Translate ${checks.length} rows via AI? Fields filled only (save later).`))) return;

  const btn = document.querySelector(`#${anchor} .tr-bulk-ai`);
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + (RTL?'جاري...':'Working...');

  let ok = 0, fail = 0;
  for (const cb of checks) {
    const id = cb.value;
    try {
      const fd = new FormData();
      fd.append('tbl', tbl);
      fd.append('id', id);
      const r = await fetch('api/translate_one.php', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok && d.suggestion) {
        const input = document.getElementById('trInput' + anchor + '_' + id);
        if (input) { input.value = d.suggestion; input.style.background = '#fef3c7'; setTimeout(()=>input.style.background='', 1200); }
        ok++;
      } else { fail++; }
    } catch { fail++; }
  }
  btn.innerHTML = RTL ? `✅ ${ok} / ${checks.length}` : `✅ ${ok} / ${checks.length}`;
  setTimeout(() => { btn.disabled = false; btn.innerHTML = orig; }, 2000);
}

// ── حفظ جماعي للصفوف المحددة (Bulk Save) ───────────────────
async function bulkSave(tbl, anchor) {
  const checks = Array.from(document.querySelectorAll(`input.tr-check[data-anchor="${anchor}"]:checked`));
  if (!checks.length) { alert(RTL ? 'حدد صف واحد على الأقل' : 'Select at least one row'); return; }

  const btn = document.querySelector(`#${anchor} .tr-bulk-save`);
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + (RTL?'جاري...':'Saving...');

  let ok = 0, fail = 0;
  for (const cb of checks) {
    const id = cb.value;
    const input = document.getElementById('trInput' + anchor + '_' + id);
    if (!input || !input.value.trim()) { fail++; continue; }
    try {
      const fd = new FormData();
      fd.append('tbl', tbl);
      fd.append('id', id);
      fd.append('value', input.value.trim());
      const r = await fetch('api/translate_one.php', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok) {
        // لون الصف أخضر
        const row = document.getElementById('trRow' + anchor + '_' + id);
        if (row) { row.classList.remove('pending'); row.classList.add('done'); }
        input.style.background = '#d1fae5'; setTimeout(()=>input.style.background='', 1200);
        ok++;
      } else { fail++; }
    } catch { fail++; }
  }
  btn.innerHTML = RTL ? `✅ ${ok} محفوظ` : `✅ ${ok} saved`;
  setTimeout(() => { btn.disabled = false; btn.innerHTML = orig; }, 2000);
}

// ── ترجمة عنصر واحد بالـ AI (للبطاقات) ──────────────────────
async function aiSuggest(tbl, id, anchor) {
    const input = document.getElementById('trInput' + anchor + '_' + id);
    const card  = document.getElementById('trCard'  + anchor + '_' + id);
    if (!input || !card) return;

    const btn = card.querySelector('.tr-btn-ai');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('tbl', tbl);
        fd.append('id', id);
        const r = await fetch('api/translate_one.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok && d.suggestion) {
            input.value = d.suggestion;
            input.style.background = '#fef3c7';
            setTimeout(() => input.style.background = '', 1500);
        } else {
            alert('⚠ ' + (d.msg || 'Failed') + (d.detail ? '\n' + d.detail : ''));
        }
    } catch (e) {
        alert('Network: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

// ── حفظ ترجمة عنصر واحد ─────────────────────────────────────
async function saveTranslation(tbl, id, anchor) {
    const input = document.getElementById('trInput' + anchor + '_' + id);
    const card  = document.getElementById('trCard'  + anchor + '_' + id);
    const status = document.getElementById('trStatus' + anchor + '_' + id);
    if (!input || !card) return;

    const val = (input.value || '').trim();
    if (!val) { input.focus(); input.style.borderColor = '#dc2626'; setTimeout(() => input.style.borderColor = '', 1500); return; }

    const saveBtn = card.querySelector('.tr-btn-save');
    const origHtml = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('tbl', tbl);
        fd.append('id', id);
        fd.append('value', val);
        fd.append('csrf', '<?= csrf_token() ?>');
        const r = await fetch('api/translate_one_save.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            // نجاح: حدّث الـ card كـ done
            card.classList.remove('pending');
            card.classList.add('done');
            if (status) {
                status.className = 'tr-status done';
                status.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span>' + val.replace(/[<>]/g, '') + '</span>';
            }
            // غيّر لون الصف إلى أخضر
            const row = document.getElementById('trRow' + anchor + '_' + id);
            if (row) { row.classList.remove('pending'); row.classList.add('done'); }
            input.style.background = '#d1fae5';
            setTimeout(() => input.style.background = '', 1200);
        } else {
            alert('⚠ ' + (d.msg || 'Failed'));
            if (status) {
                status.className = 'tr-status err';
                status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (d.msg || 'Failed');
                setTimeout(() => { status.className = ''; status.innerHTML = ''; }, 3000);
            }
        }
    } catch (e) {
        alert('Network: ' + e.message);
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origHtml;
    }
}
</script>