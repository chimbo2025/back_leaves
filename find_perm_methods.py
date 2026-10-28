with open(r"E:\Dev\Zee\backend-leave\app\Services\PermissionService.php") as f:
    for i,line in enumerate(f,1):
        if 'public function' in line:
            print(f"{i}: {line.strip()[:120]}")
