<?php

/**
 * Progress Calculator Functions
 * Handles automatic progress calculation for PON based on tasks
 */

/**
 * Calculate PON progress based on tasks
 * @param string $ponCode
 * @return int Progress percentage (0-100)
 */
function calculatePonProgress(string $ponCode): int
{
    try {
        // Get all tasks for this PON
        $tasks = fetchAll('SELECT progress FROM tasks WHERE pon = ?', [$ponCode]);

        if (empty($tasks)) {
            return 0;
        }

        // Calculate average progress from all tasks
        $totalProgress = array_sum(array_map(fn($task) => (int)($task['progress'] ?? 0), $tasks));
        $averageProgress = (int)round($totalProgress / count($tasks));

        return max(0, min(100, $averageProgress));
    } catch (Exception $e) {
        error_log('Error calculating PON progress: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Update PON progress in database
 * @param string $ponCode
 * @return bool Success status
 */
function updatePonProgress(string $ponCode): bool
{
    try {
        $newProgress = calculatePonProgress($ponCode);
        $rowsUpdated = update('pon', ['progress' => $newProgress], 'pon = :pon', ['pon' => $ponCode]);
        return $rowsUpdated > 0;
    } catch (Exception $e) {
        error_log('Error updating PON progress: ' . $e->getMessage());
        return false;
    }
}

/**
 * Auto-update progress based on status
 * @param string $ponCode
 * @param string $status
 * @return bool Success status  
 */
function updateProgressByStatus(string $ponCode, string $status): bool
{
    try {
        $progress = 0;

        switch (strtolower($status)) {
            case 'selesai':
                $progress = 100;
                // Also update all tasks to 100% and 'Done'
                update('tasks', ['progress' => 100, 'status' => 'Done'], 'pon = :pon', ['pon' => $ponCode]);
                break;
            case 'pending':
            case 'delayed':
                $progress = calculatePonProgress($ponCode); // Keep calculated progress
                break;
            case 'progres':
            default:
                $progress = calculatePonProgress($ponCode); // Calculate from tasks
                break;
        }

        $rowsUpdated = update('pon', ['progress' => $progress], 'pon = :pon', ['pon' => $ponCode]);
        return $rowsUpdated > 0;
    } catch (Exception $e) {
        error_log('Error updating progress by status: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sync all PON progress (for maintenance/cleanup)
 * @return array Results summary
 */
function syncAllPonProgress(): array
{
    try {
        $ponList = fetchAll('SELECT pon, status FROM pon');
        $updated = 0;
        $errors = 0;

        foreach ($ponList as $pon) {
            if (updateProgressByStatus($pon['pon'], $pon['status'])) {
                $updated++;
            } else {
                $errors++;
            }
        }

        return [
            'success' => true,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($ponList)
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
