<?php
$files = [
    'is_ind_ctrl' => ['file'=>'app/Http/Controllers/Api/OrganizationController.php','pat'=>'is_independent'],
    'is_ind_model'=> ['file'=>'app/Models/Department.php','pat'=>'is_independent'],
    'unit_type_rule'=>['file'=>'app/Http/Requests/StoreUnitRequest.php','pat'=>'in:independent_unit'],
    'headGrant'=>['file'=>'app/Models/Department.php','pat'=>'function headGrant'],
    'casts_prop'=>['file'=>'app/Models/Department.php','pat'=>'protected $casts'],
    'code_auto'=>['file'=>'app/Http/Controllers/Api/OrganizationController.php','pat'=>"if (empty(\$data['code']))"],
];
foreach ($files as $k=>$v) {
    $c = 0;
    if (file_exists($v['file']) && ($lines = file($v['file']))) {
        foreach ($lines as $ln) if (strpos($ln, $v['pat']) !== false) $c++;
    }
    echo "$k = $c\n";
}
