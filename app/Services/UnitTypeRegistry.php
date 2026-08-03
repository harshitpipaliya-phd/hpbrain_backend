<?php

declare(strict_types=1);

namespace App\Services;

final class UnitTypeRegistry
{
    private static ?array $types = null;

    public static function getTypes(): array
    {
        if (self::$types === null) {
            self::$types = [
                'department' => [
                    'key'           => 'department',
                    'label'         => 'Department',
                    'description'   => 'Standard organizational department',
                    'icon'          => 'account_tree',
                    'allowedChildren' => ['team', 'sub_department'],
                    'requiredFields' => ['name', 'code'],
                ],
                'school' => [
                    'key'           => 'school',
                    'label'         => 'School',
                    'description'   => 'K-12 or higher education school',
                    'icon'          => 'school',
                    'allowedChildren' => ['department', 'grade', 'program'],
                    'requiredFields' => ['name', 'code'],
                ],
                'faculty' => [
                    'key'           => 'faculty',
                    'label'         => 'Faculty',
                    'description'   => 'Academic faculty or division',
                    'icon'          => 'account_balance',
                    'allowedChildren' => ['department', 'program', 'course'],
                    'requiredFields' => ['name', 'code'],
                ],
                'branch' => [
                    'key'           => 'branch',
                    'label'         => 'Branch',
                    'description'   => 'Corporate or government branch office',
                    'icon'          => 'location_city',
                    'allowedChildren' => ['department', 'team'],
                    'requiredFields' => ['name', 'code', 'location'],
                ],
                'clinical_unit' => [
                    'key'           => 'clinical_unit',
                    'label'         => 'Clinical Unit',
                    'description'   => 'Healthcare clinical unit or department',
                    'icon'          => 'local_hospital',
                    'allowedChildren' => ['team', 'ward', 'service'],
                    'requiredFields' => ['name', 'code', 'head_id'],
                ],
                'division' => [
                    'key'           => 'division',
                    'label'         => 'Division',
                    'description'   => 'Business division or segment',
                    'icon'          => 'view_week',
                    'allowedChildren' => ['department', 'business_unit'],
                    'requiredFields' => ['name', 'code'],
                ],
                'business_unit' => [
                    'key'           => 'business_unit',
                    'label'         => 'Business Unit',
                    'description'   => 'Strategic business unit',
                    'icon'          => 'business',
                    'allowedChildren' => ['team', 'department'],
                    'requiredFields' => ['name', 'code'],
                ],
                'custom' => [
                    'key'           => 'custom',
                    'label'         => 'Custom',
                    'description'   => 'Custom organizational unit',
                    'icon'          => 'extension',
                    'allowedChildren' => [],
                    'requiredFields' => ['name'],
                ],
            ];
        }

        return self::$types;
    }

    public static function getType(string $typeKey): ?array
    {
        $types = self::getTypes();

        return $types[$typeKey] ?? null;
    }

    public static function registerType(string $typeKey, array $definition): void
    {
        if (self::$types === null) {
            self::getTypes();
        }

        self::$types[$typeKey] = array_merge([
            'key'             => $typeKey,
            'label'           => ucfirst(str_replace('_', ' ', $typeKey)),
            'description'     => '',
            'icon'            => 'extension',
            'allowedChildren' => [],
            'requiredFields'  => ['name'],
        ], $definition);
    }
}
