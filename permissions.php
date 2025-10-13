<?php

declare(strict_types=1);

class Permission
{
    private static $permissions = [
        '*' => 'Full Access',
        
        // Project permissions
        'project.view' => 'View Projects',
        'project.create' => 'Create Projects',
        'project.edit' => 'Edit Projects',
        'project.delete' => 'Delete Projects',
        
        // Task permissions
        'task.view' => 'View Tasks',
        'task.create' => 'Create Tasks',
        'task.edit' => 'Edit Tasks',
        'task.delete' => 'Delete Tasks',
        'task.edit_own' => 'Edit Own Tasks Only',
        
        // User permissions
        'user.view' => 'View Users',
        'user.create' => 'Create Users',
        'user.edit' => 'Edit Users',
        'user.delete' => 'Delete Users',
        
        // Report permissions
        'report.view' => 'View Reports',
        'report.export' => 'Export Reports',
        
        // System permissions
        'system.settings' => 'System Settings'
    ];

    public static function getAllPermissions(): array
    {
        return self::$permissions;
    }

    public static function hasPermission(array $userPermissions, string $requiredPermission): bool
    {
        // Jika user memiliki akses penuh
        if (in_array('*', $userPermissions)) {
            return true;
        }

        // Check permission spesifik
        return in_array($requiredPermission, $userPermissions);
    }

    public static function getUserPermissions(int $roleId): array
    {
        $role = fetchOne('SELECT permissions FROM roles WHERE id = ?', [$roleId]);
        if (!$role || empty($role['permissions'])) {
            return [];
        }

        $permissions = json_decode($role['permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }

    // Helper untuk check jika user bisa edit
    public static function canEdit(array $userPermissions): bool
    {
        return self::hasPermission($userPermissions, 'task.edit') || 
               self::hasPermission($userPermissions, 'task.create') ||
               self::hasPermission($userPermissions, '*');
    }

    // Helper untuk check jika user hanya view only
    public static function isViewOnly(array $userPermissions): bool
    {
        return !self::canEdit($userPermissions) && 
               self::hasPermission($userPermissions, 'project.view');
    }

    // Check if user is admin
    public static function isAdmin(array $userPermissions): bool
    {
        return self::hasPermission($userPermissions, '*');
    }
}

// Function helper untuk check permission di views
function can(string $permission): bool
{
    if (!isset($_SESSION['user_permissions'])) {
        return false;
    }
    
    return Permission::hasPermission($_SESSION['user_permissions'], $permission);
}

// Helper untuk check jika user bisa edit
function canEdit(): bool
{
    if (!isset($_SESSION['user_permissions'])) {
        return false;
    }
    return Permission::canEdit($_SESSION['user_permissions']);
}

// Helper untuk check jika user hanya view only
function isViewOnly(): bool
{
    if (!isset($_SESSION['user_permissions'])) {
        return false;
    }
    return Permission::isViewOnly($_SESSION['user_permissions']);
}

// Helper untuk check jika user adalah admin
function isAdmin(): bool
{
    if (!isset($_SESSION['user_permissions'])) {
        return false;
    }
    return Permission::isAdmin($_SESSION['user_permissions']);
}

?>