<?php
// Load .env manually
$env = file_get_contents(__DIR__ . '/.env');
$get = function ($key, $env) {
    if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $m)) {
        return trim(explode(' ', $m[1])[0]);
    }
    return '';
};

$host = $get('DB_HOST', $env);
$port = $get('DB_PORT', $env);
$db   = $get('DB_DATABASE', $env);
$user = $get('DB_USERNAME', $env);
$pass = $get('DB_PASSWORD', $env);

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Get all active organizations
    $orgs = $pdo->query("SELECT sub_institute_id AS tenant_id, organization_name, organization_code, industry_type FROM institute_detail WHERE deleted_at IS NULL ORDER BY sub_institute_id")->fetchAll();

    if (!$orgs) {
        echo "No organizations found.\n";
        exit(0);
    }

    foreach ($orgs as $org) {
        echo "\n========================================\n";
        echo "ORGANIZATION: {$org['organization_name']} ({$org['organization_code']})\n";
        echo "Tenant ID: {$org['tenant_id']} | Industry: {$org['industry_type']}\n";
        echo "========================================\n";

        // Get departments for this organization
        $stmt = $pdo->prepare("SELECT id, department, parent_id, status FROM hrms_departments WHERE sub_institute_id = ? AND deleted_at IS NULL ORDER BY parent_id, id");
        $stmt->execute([$org['tenant_id']]);
        $departments = $stmt->fetchAll();

        if (!$departments) {
            echo "  (no departments)\n";
            continue;
        }

        // Build department tree
        $byParent = [];
        foreach ($departments as $dept) {
            $byParent[(int)($dept['parent_id'] ?? 0)][] = $dept;
        }

        $list = function ($parentId = 0, $indent = '  ') use ($byParent, $pdo, $org) {
            if (empty($byParent[$parentId])) return;
            foreach ($byParent[$parentId] as $dept) {
                echo "{$indent}DEPARTMENT: {$dept['department']} (ID: {$dept['id']})\n";
                
                // Get people in this department
                $stmt = $pdo->prepare("SELECT id, employee_no, first_name, last_name, email, gender, status FROM tbluser WHERE sub_institute_id = ? AND department_id = ? AND deleted_at IS NULL ORDER BY first_name, last_name");
                $stmt->execute([$org['tenant_id'], $dept['id']]);
                $people = $stmt->fetchAll();

                if ($people) {
                    foreach ($people as $p) {
                        echo "{$indent}  -> {$p['first_name']} {$p['last_name']} ({$p['email']}) [{$p['employee_no']}]\n";
                    }
                } else {
                    echo "{$indent}  -> (no people)\n";
                }

                // Recurse for sub-departments
                $this->list($dept['id'], $indent . '  ');
            }
        };

        // Simple flat list with indentation since we can't use $this in closure easily
        $list = function ($parentId = 0, $indent = '  ') use ($byParent, $pdo, $org, &$list) {
            if (empty($byParent[$parentId])) return;
            foreach ($byParent[$parentId] as $dept) {
                echo "{$indent}DEPARTMENT: {$dept['department']} (ID: {$dept['id']})\n";
                
                $stmt = $pdo->prepare("SELECT id, employee_no, first_name, last_name, email, gender, status FROM tbluser WHERE sub_institute_id = ? AND department_id = ? AND deleted_at IS NULL ORDER BY first_name, last_name");
                $stmt->execute([$org['tenant_id'], $dept['id']]);
                $people = $stmt->fetchAll();

                if ($people) {
                    foreach ($people as $p) {
                        echo "{$indent}  -> {$p['first_name']} {$p['last_name']} ({$p['email']}) [{$p['employee_no']}]\n";
                    }
                } else {
                    echo "{$indent}  -> (no people)\n";
                }

                $list($dept['id'], $indent . '  ');
            }
        };

        $list();
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
