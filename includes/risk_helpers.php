<?php
/**
 * Risk Score helpers — 7-factor weighted scoring (Excel template parity)
 *
 * Implements the MOH Risk Score formula from the Excel template
 * (نموذج حصر بيانات طلبات الاحلال الاجهزة الطبي وغير الطبي).
 *
 * Score breakdown (max 100):
 *   - Age Score (10)              : from date_placed_in_service
 *   - Condition Score (20)        : manual (excellent..critical) + smart default
 *   - Utilization Score (15)      : manual 0.000-1.000
 *   - Breakdown Score (15)        : computed from complaints in last 12m
 *   - Maintenance Ratio Score (10): maintenance_cost_ytd / replacement_cost
 *   - Downtime Score (10)         : manual (high/medium/low) + smart default
 *   - Operational Pressure (10)   : manual per asset + smart default
 *
 * Bands: Critical(>=70), High(>=50), Medium(>=30), Low(<30), Unscored(no data)
 *
 * Pattern: idempotent. compute_risk_score($id, $force=true) always produces
 *          the same result for the same input. Safe to call from cron/hooks.
 *
 * @package PMSH_Assets
 * @subpackage Risk
 * @since Migration 034 (2026-07-26)
 */

if (!defined('RISK_HELPERS_LOADED')) {
    define('RISK_HELPERS_LOADED', true);

    /**
     * Get the assessment year (used for age calculation).
     * Centralized so we can change it once if a fiscal year shifts.
     */
    function risk_assessment_year(): int {
        return (int)date('Y');
    }

    /**
     * Smart default for condition_status based on age.
     * Buckets match the Excel Age Score so the two stay consistent.
     *
     * @param int|null $age Age in years (null = unknown)
     * @return string enum value
     */
    function risk_smart_condition(?int $age): string {
        if ($age === null) return 'unknown';
        if ($age < 3)  return 'excellent';
        if ($age < 7)  return 'good';
        if ($age < 12) return 'fair';
        if ($age < 20) return 'poor';
        return 'critical';
    }

    /**
     * Smart default for downtime_impact based on criticality_class.
     *
     * @param string|null $criticality_class 'A'|'B'|'C'|null
     * @return string enum value
     */
    function risk_smart_downtime(?string $criticality_class): string {
        return match($criticality_class) {
            'A' => 'high',
            'B' => 'medium',
            'C' => 'low',
            default => 'low',
        };
    }

    /**
     * Smart default for operational_pressure based on criticality_class.
     * (User asked for per-asset setting — this is the fallback for the initial
     *  value; admins can override per asset.)
     *
     * @param string|null $criticality_class 'A'|'B'|'C'|null
     * @return string enum value
     */
    function risk_smart_pressure(?string $criticality_class): string {
        return match($criticality_class) {
            'A' => 'critical',
            'B' => 'high',
            'C' => 'medium',
            default => 'low',
        };
    }

    /**
     * Age score from age in years.
     * Excel formula: >=12→10, >=10→8, >=7→6, >=5→4, else 2
     *
     * @param int|null $age Age in years
     * @return int 0-10
     */
    function risk_age_score(?int $age): int {
        if ($age === null || $age < 0) return 0;
        if ($age >= 12) return 10;
        if ($age >= 10) return 8;
        if ($age >= 7)  return 6;
        if ($age >= 5)  return 4;
        return 2;
    }

    /**
     * Condition score.
     * Excel: Critical=20, Poor=16, Fair=10, Good=5, Excellent=2
     */
    function risk_condition_score(?string $condition): int {
        return match($condition) {
            'critical'  => 20,
            'poor'      => 16,
            'fair'      => 10,
            'good'      => 5,
            'excellent' => 2,
            default     => 0,  // unknown / null
        };
    }

    /**
     * Utilization score (0-1 → 0-15).
     * Excel: >=0.9→15, >=0.75→12, >=0.6→8, >0→4, else 0
     */
    function risk_utilization_score(?float $util): int {
        if ($util === null || $util <= 0) return 0;
        if ($util >= 0.9)  return 15;
        if ($util >= 0.75) return 12;
        if ($util >= 0.6)  return 8;
        return 4;
    }

    /**
     * Breakdown score from count in last 12 months.
     * Excel: >=12→15, >=8→12, >=4→8, >=1→4, else 0
     */
    function risk_breakdown_score(int $count): int {
        if ($count >= 12) return 15;
        if ($count >= 8)  return 12;
        if ($count >= 4)  return 8;
        if ($count >= 1)  return 4;
        return 0;
    }

    /**
     * Maintenance ratio score = maintenance_cost / replacement_cost.
     * Excel: >=0.2→10, >=0.15→8, >=0.1→6, >=0.05→3, else 1
     * (returns 0 if replacement_cost is 0/null — no point scoring the ratio)
     */
    function risk_maint_ratio_score(float $maint_cost, float $replacement_cost): int {
        if ($replacement_cost <= 0) return 0;
        $ratio = $maint_cost / $replacement_cost;
        if ($ratio >= 0.2)  return 10;
        if ($ratio >= 0.15) return 8;
        if ($ratio >= 0.1)  return 6;
        if ($ratio >= 0.05) return 3;
        return 1;
    }

    /**
     * Downtime score.
     * Excel: High=10, Medium=6, Low=2
     */
    function risk_downtime_score(?string $downtime): int {
        return match($downtime) {
            'high'   => 10,
            'medium' => 6,
            'low'    => 2,
            default  => 0,
        };
    }

    /**
     * Operational pressure score.
     * Excel: Critical=10, High=8, Medium=5, Low=2
     */
    function risk_pressure_score(?string $pressure): int {
        return match($pressure) {
            'critical' => 10,
            'high'     => 8,
            'medium'   => 5,
            'low'      => 2,
            default    => 0,
        };
    }

    /**
     * Risk band from total score.
     * Excel: >=70 Critical, >=50 High, >=30 Medium, else Low
     */
    function risk_band_from_score(float $total): string {
        if ($total >= 70) return 'critical';
        if ($total >= 50) return 'high';
        if ($total >= 30) return 'medium';
        return 'low';
    }

    /**
     * Recommended action per band.
     * Excel: Critical=Escalate, High=Prioritize, Medium=Monitor, Low=No action
     */
    function risk_recommended_action(string $band): string {
        return match($band) {
            'critical' => 'Escalate for immediate decision (تصعيد عاجل)',
            'high'     => 'Prioritize in next funding cycle (أولوية في دورة التمويل)',
            'medium'   => 'Monitor and validate need (مراقبة وتحقق)',
            'low'      => 'No immediate action (لا حاجة لإجراء حالي)',
            default    => '',
        };
    }

    /**
     * Data completeness percentage (0-100).
     * 4 manual fields × 25% each = 100% when all filled.
     * beneficiaries_count is optional (5% bonus) — but for simplicity we keep
     * it at 100% max and don't add bonus.
     */
    function risk_data_completeness_pct(?string $condition, ?float $util, ?string $downtime, ?string $pressure): int {
        $filled = 0;
        if ($condition && $condition !== 'unknown') $filled++;
        if ($util !== null) $filled++;
        if ($downtime && $downtime !== 'unknown') $filled++;
        if ($pressure && $pressure !== 'unknown') $filled++;
        return (int)($filled * 25);
    }

    /**
     * Get breakdowns count for an asset in the last 12 months from complaints.
     * Counts BOTH new complaints (open) AND resolved ones — anything logged.
     */
    function risk_get_breakdowns_12m(PDO $pdo, int $asset_id): int {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM complaints
            WHERE asset_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ");
        $stmt->execute([$asset_id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get maintenance cost YTD for an asset.
     * Reads from assets.total_maintenance_cost (the canonical field for the year).
     * If you want to compute from work_orders, do it via a different function
     * (risk_get_maint_from_work_orders) and decide which to use.
     */
    function risk_get_maintenance_cost_ytd(PDO $pdo, int $asset_id): float {
        $stmt = $pdo->prepare("SELECT COALESCE(total_maintenance_cost, 0) FROM assets WHERE id = ?");
        $stmt->execute([$asset_id]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Read the full asset row needed for risk computation.
     * Returns associative array or null if not found.
     */
    function risk_get_asset(PDO $pdo, int $asset_id): ?array {
        $stmt = $pdo->prepare("
            SELECT id, date_placed_in_service, criticality_class,
                   condition_status, utilization_rate, downtime_impact,
                   operational_pressure, beneficiaries_count,
                   breakdowns_12m, maintenance_cost_ytd, cost
            FROM assets WHERE id = ?
        ");
        $stmt->execute([$asset_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Compute the full risk score for one asset and persist the result.
     *
     * Steps:
     *   1. Read asset row
     *   2. Recompute breakdowns_12m (auto from complaints)
     *   3. Recompute maintenance_cost_ytd (auto from total_maintenance_cost)
     *   4. Compute all 7 sub-scores
     *   5. Sum total, derive band, set funding_gap + recommended_action
     *   6. Compute data_completeness_pct
     *   7. UPDATE assets with all 9 cached columns + last_computed_at
     *
     * @param PDO $pdo
     * @param int $asset_id
     * @param bool $refresh_auto If true, recompute breakdowns_12m + maint_cost_ytd from source
     *                            (otherwise keep cached values). Default true.
     * @return array Result with 'success', 'total', 'band', 'completeness'
     */
    function compute_risk_score(PDO $pdo, int $asset_id, bool $refresh_auto = true): array {
        $asset = risk_get_asset($pdo, $asset_id);
        if (!$asset) {
            return ['success' => false, 'error' => 'Asset not found'];
        }

        // 1) Age from date_placed_in_service
        $age = null;
        if (!empty($asset['date_placed_in_service'])) {
            try {
                $d = new DateTimeImmutable($asset['date_placed_in_service']);
                $age = risk_assessment_year() - (int)$d->format('Y');
                if ($age < 0) $age = 0;
            } catch (Exception $e) {
                $age = null;
            }
        }

        // 2) Auto-refresh breakdowns_12m + maintenance_cost_ytd if requested
        $breakdowns = (int)($asset['breakdowns_12m'] ?? 0);
        $maint_cost = (float)($asset['maintenance_cost_ytd'] ?? 0);
        if ($refresh_auto) {
            $breakdowns = risk_get_breakdowns_12m($pdo, $asset_id);
            $maint_cost = risk_get_maintenance_cost_ytd($pdo, $asset_id);
        }

        // 3) Compute the 7 sub-scores
        $age_s      = risk_age_score($age);
        $cond_s     = risk_condition_score($asset['condition_status']);
        $util_s     = risk_utilization_score($asset['utilization_rate'] !== null ? (float)$asset['utilization_rate'] : null);
        $break_s    = risk_breakdown_score($breakdowns);
        $maint_s    = risk_maint_ratio_score($maint_cost, (float)($asset['cost'] ?? 0));
        $down_s     = risk_downtime_score($asset['downtime_impact']);
        $press_s    = risk_pressure_score($asset['operational_pressure']);
        $total      = $age_s + $cond_s + $util_s + $break_s + $maint_s + $down_s + $press_s;

        // 4) Band, funding_gap, recommended action
        $band           = risk_band_from_score($total);
        $funding_gap    = in_array($band, ['critical', 'high'], true) ? (float)($asset['cost'] ?? 0) : 0.0;
        $recommended    = risk_recommended_action($band);

        // 5) Data completeness
        $completeness   = risk_data_completeness_pct(
            $asset['condition_status'],
            $asset['utilization_rate'] !== null ? (float)$asset['utilization_rate'] : null,
            $asset['downtime_impact'],
            $asset['operational_pressure']
        );

        // 6) If completeness is 0, override band to 'unscored'
        if ($completeness === 0) {
            $band = 'unscored';
            $recommended = '';
        }

        // 7) Persist
        $stmt = $pdo->prepare("
            UPDATE assets SET
                breakdowns_12m         = ?,
                maintenance_cost_ytd   = ?,
                total_risk_score       = ?,
                risk_band              = ?,
                funding_gap            = ?,
                recommended_action     = ?,
                data_completeness_pct  = ?,
                last_computed_at       = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $breakdowns,
            $maint_cost,
            $total,
            $band,
            $funding_gap,
            $recommended,
            $completeness,
            $asset_id,
        ]);

        return [
            'success'      => true,
            'asset_id'     => $asset_id,
            'age'          => $age,
            'scores'       => [
                'age'      => $age_s,
                'condition'=> $cond_s,
                'util'     => $util_s,
                'break'    => $break_s,
                'maint'    => $maint_s,
                'downtime' => $down_s,
                'pressure' => $press_s,
            ],
            'total'        => $total,
            'band'         => $band,
            'completeness' => $completeness,
            'funding_gap'  => $funding_gap,
        ];
    }

    /**
     * Bulk update a single field on multiple assets.
     * Used for the "تحديث جماعي" UI flow.
     *
     * @param PDO $pdo
     * @param int[] $asset_ids
     * @param string $field One of: condition_status, utilization_rate, downtime_impact, operational_pressure, beneficiaries_count
     * @param mixed $value The new value
     * @param int|null $user_id Acting user (for audit)
     * @return array {updated, failed, errors}
     */
    function risk_bulk_update_field(PDO $pdo, array $asset_ids, string $field, $value, ?int $user_id = null): array {
        $allowed = [
            'condition_status', 'utilization_rate', 'downtime_impact',
            'operational_pressure', 'beneficiaries_count',
        ];
        if (!in_array($field, $allowed, true)) {
            return ['updated' => 0, 'failed' => 0, 'errors' => ["Invalid field: $field"]];
        }
        if (empty($asset_ids)) {
            return ['updated' => 0, 'failed' => 0, 'errors' => ['No asset IDs provided']];
        }

        $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
        $sql = "UPDATE assets SET $field = ?, last_manual_assessment_at = NOW(), last_manual_assessment_by = ? WHERE id IN ($placeholders)";
        $params = array_merge([$value, $user_id], $asset_ids);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $updated = $stmt->rowCount();
        } catch (Exception $e) {
            return ['updated' => 0, 'failed' => count($asset_ids), 'errors' => [$e->getMessage()]];
        }

        // Recompute risk for all updated assets
        $failed = 0;
        $errors = [];
        foreach ($asset_ids as $aid) {
            $r = compute_risk_score($pdo, (int)$aid, true);
            if (!$r['success']) {
                $failed++;
                $errors[] = "Asset $aid: " . ($r['error'] ?? 'unknown');
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Get risk distribution stats for dashboard/reports.
     */
    function risk_get_distribution(PDO $pdo, ?array $scope = null): array {
        $where = "1=1";
        $params = [];
        if ($scope) {
            // scope is ['where' => SQL, 'params' => []] from data_scope()
            $where .= " AND (" . $scope['where'] . ")";
            $params = $scope['params'];
        }
        $sql = "SELECT risk_band, COUNT(*) AS cnt, COALESCE(SUM(funding_gap), 0) AS funding
                 FROM assets WHERE $where
                 GROUP BY risk_band";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bands = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unscored' => 0];
        $funding_by_band = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $total = 0;
        $total_funding = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $b = $row['risk_band'] ?: 'unscored';
            $bands[$b] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
            if (isset($funding_by_band[$b])) {
                $funding_by_band[$b] = (float)$row['funding'];
                $total_funding += (float)$row['funding'];
            }
        }
        return [
            'bands' => $bands,
            'total' => $total,
            'total_funding' => $total_funding,
            'funding_by_band' => $funding_by_band,
        ];
    }

    /**
     * Get top N highest-risk assets.
     */
    function risk_get_top(PDO $pdo, int $limit = 20, ?array $scope = null): array {
        $where = "risk_band IN ('critical','high')";
        $params = [];
        if ($scope) {
            $where .= " AND (" . $scope['where'] . ")";
            $params = $scope['params'];
        }
        $sql = "SELECT id, tag_number, asset_number, description, cat_level1,
                       criticality_class, condition_status, utilization_rate,
                       downtime_impact, operational_pressure, beneficiaries_count,
                       breakdowns_12m, maintenance_cost_ytd, total_risk_score,
                       risk_band, funding_gap, data_completeness_pct,
                       last_computed_at, date_placed_in_service, cost
                FROM assets WHERE $where
                ORDER BY total_risk_score DESC, funding_gap DESC
                LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get data quality stats (how complete is our risk data?).
     */
    function risk_get_data_quality(PDO $pdo, ?array $scope = null): array {
        $where = "1=1";
        $params = [];
        if ($scope) {
            $where .= " AND (" . $scope['where'] . ")";
            $params = $scope['params'];
        }
        $sql = "SELECT
                  COUNT(*) AS total,
                  SUM(CASE WHEN data_completeness_pct = 100 THEN 1 ELSE 0 END) AS complete_100,
                  SUM(CASE WHEN data_completeness_pct >= 75 THEN 1 ELSE 0 END) AS complete_75,
                  SUM(CASE WHEN data_completeness_pct >= 50 THEN 1 ELSE 0 END) AS complete_50,
                  SUM(CASE WHEN data_completeness_pct > 0 THEN 1 ELSE 0 END) AS any_data,
                  SUM(CASE WHEN data_completeness_pct = 0 THEN 1 ELSE 0 END) AS zero_data
                FROM assets WHERE $where";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get old assets (age >= expected_replacement).
     * These are the natural candidates for replacement.
     */
    function risk_get_old_assets(PDO $pdo, ?array $scope = null, int $limit = 50): array {
        $where = "date_placed_in_service IS NOT NULL
                  AND TIMESTAMPDIFF(YEAR, date_placed_in_service, NOW()) >= COALESCE(useful_life_years, 10)";
        $params = [];
        if ($scope) {
            $where .= " AND (" . $scope['where'] . ")";
            $params = $scope['params'];
        }
        $sql = "SELECT id, tag_number, description, cat_level1, date_placed_in_service,
                       useful_life_years, TIMESTAMPDIFF(YEAR, date_placed_in_service, NOW()) AS age_years,
                       total_risk_score, risk_band, funding_gap, cost
                FROM assets WHERE $where
                ORDER BY age_years DESC, cost DESC
                LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recompute risk score for ALL assets in a single pass.
     * Used by:
     *   - Initial backfill
     *   - Cron job (nightly)
     *   - Admin "re-evaluate all" button
     *
     * @param PDO $pdo
     * @param int $batch_size For memory control — set to 100-500
     * @return array {total, succeeded, failed, errors}
     */
    function risk_recompute_all(PDO $pdo, int $batch_size = 200): array {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        $offset = 0;
        while ($offset < $total) {
            // MariaDB 10.4 has issues with placeholders in LIMIT/OFFSET — inline ints (already cast to int)
            $stmt = $pdo->query("SELECT id FROM assets ORDER BY id LIMIT $batch_size OFFSET $offset");
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $r = compute_risk_score($pdo, (int)$id, true);
                if ($r['success']) $succeeded++;
                else {
                    $failed++;
                    if (count($errors) < 20) $errors[] = "Asset $id: " . ($r['error'] ?? '?');
                }
            }
            $offset += $batch_size;
        }

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Apply smart defaults to all assets that haven't been assessed yet.
     * Sets condition_status + downtime_impact + operational_pressure based
     * on age and criticality_class. Used during backfill.
     *
     * @param PDO $pdo
     * @param bool $only_unset If true, only update assets with 'unknown' values
     * @return int Number of assets updated
     */
    function risk_apply_smart_defaults(PDO $pdo, bool $only_unset = true): int {
        $where = $only_unset ? "WHERE condition_status = 'unknown' OR condition_status IS NULL" : "";
        $stmt = $pdo->prepare("SELECT id, date_placed_in_service, criticality_class FROM assets $where");
        $stmt->execute();
        $count = 0;
        $update = $pdo->prepare("
            UPDATE assets SET
                condition_status     = ?,
                downtime_impact      = ?,
                operational_pressure = ?,
                last_manual_assessment_at = NULL,
                last_computed_at = NULL
            WHERE id = ?
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $age = null;
            if (!empty($row['date_placed_in_service'])) {
                try {
                    $d = new DateTimeImmutable($row['date_placed_in_service']);
                    $age = risk_assessment_year() - (int)$d->format('Y');
                } catch (Exception $e) { $age = null; }
            }
            $cond = risk_smart_condition($age);
            $down = risk_smart_downtime($row['criticality_class']);
            $press = risk_smart_pressure($row['criticality_class']);
            $update->execute([$cond, $down, $press, $row['id']]);
            $count++;
        }
        return $count;
    }
}
