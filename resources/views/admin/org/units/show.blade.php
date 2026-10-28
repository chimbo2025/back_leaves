@extends('layouts.app')

@section('content')
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="page-header">
                <span>Independent Unit Detail: {{ $unit->name }}</span>
            </h2>

            <!-- Unit Summary -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-white bg-success mb-3">
                        <div class="card-header">Status</div>
                        <div class="card-body">
                            <h5 class="card-title">
                                @if($unit->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Archived</span>
                                @endif
                            </h5>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-primary mb-3">
                        <div class="card-header">Code</div>
                        <div class="card-body">
                            <h5 class="card-title">{{ $unit->code ?? '—' }}</h5>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-info mb-3">
                        <div class="card-header">Unit Type</div>
                        <div class="card-body">
                            <h5 class="card-title">
                                                @if($unit->unit_type === 'independent_unit')
                                                    Independent Unit
                                                @else
                                                    {{ $unit->unit_type }}
                                                @endif
                            </h5>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Unit Details -->
            <div class="row">
                <div class="col-md-6">
                    <!-- Basic Info -->
                    <div class="card mb-4">
                        <div class="card-header">Basic Information</div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-4">Name</dt>
                                <dd class="col-sm-8">{{ $unit->name }}</dd>

                                <dt class="col-sm-4">Name (Swahili)</dt>
                                <dd class="col-sm-8">{{ $unit->name_sw ?? '—' }}</dd>

                                <dt class="col-sm-4">Unit Type</dt>
                                <dd class="col-sm-8">
                                    @if($unit->unit_type === 'division')
                                        Division
                                    @elseif($unit->unit_type === 'independent_unit')
                                        Independent Unit
                                    @else
                                        {{ $unit->unit_type }}
                                    @endif
                                </dd>

                                <dt class="col-sm-4">Reports To / Parent</dt>
                                <dd class="col-sm-8">
                                    @if($unit->parent_department_id)
                        {{ Department::find($unit->parent_department_id)->name ?? '—' }}
                                    @else
                                        <span class="text-success">Root (Top-level)</span>
                                    @endif
                                </dd>

                                <dt class="col-sm-4">Zone</dt>
                                <dd class="col-sm-8">{{ $unit->zone?->name ?? '—' }}</dd>

                                <dt class="col-sm-4">Sort Order</dt>
                                <dd class="col-sm-8">{{ $unit->sort_order }}</dd>

                                <dt class="col-sm-4">Effective From</dt>
                                <dd class="col-sm-8">{{ $unit->effective_from ?? '—' }}</dd>

                                <dt class="col-sm-4">Effective To</dt>
                                <dd class="col-sm-8">{{ $unit->effective_to ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <!-- Head Assignment -->
                    @can('units.assign_head')
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-between align-center">
                                <h5 class="mb-0">Unit Head</h5>
                            </div>
                            <div class="card-body">
                                @if($unit->headGrant)
                                    <div class="fw-bold">{{ $unit->headGrant->employee?->full_name }}</div>
                                    <div class="text-muted small">Employee ID: {{ $unit->headGrant->employee_id }}</div>
                                    <div class="text-muted small">Role: Zonal Head / Department Head</div>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="assignUnitHead({{$unit->id}})">

                                        <i class="fas fa-user-tie me-1"></i> Change Head
                                    </button>
                                @else
                                    <div class="text-muted small">No head assigned</div>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="assignUnitHead({{$unit->id}})">

                                        <i class="fas fa-user-tie me-1"></i> Assign Head
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <!-- Employees -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Employees {{ $unit->active_employee_count ?? 0 }} Active</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover small mb-0">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Position</th>
                                            <th>Zone</th>
                                            <th>Email</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(empty($unit->employees))
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3">No employees</td>
                                            </tr>
                                        @else
                                            @foreach($unit->employees as $e)
                                                <tr>
                                                    <td>{{ $e->full_name }}</td>
                                                    <td>{{ $e->position?->title ?? '—' }}</td>
                                                    <td>{{ $e->zone?->name ?? '—' }}</td>
                                                    <td>{{ $e->email }}</td>
                                                    <td>
                                                        <button class="btn btn-link btn-sm text-primary" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        @can('units.update')
                                                            <button class="btn btn-link btn-sm text-warning" title="Edit Employee">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Reports To Chain -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Reports To Chain</h5>
                        </div>
                        <div class="card-body">
                            @if($unit->parent)
                                <p><strong>Parent:</strong> {{ $unit->parent->name }}</p>
                                <p class="text-muted small">This unit reports to the selected department.</p>
                            @else
                                <p><strong>Reports To:</strong> None (Top-level independent unit)</p>
                            @endif
                        </div>
                    </div>

                    <!-- Workflow Definitions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Workflow Definitions</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">Independent units use workflow definitions from leave types. See the Leave Types section for details.</p>
                        </div>
                    </div>

                    <!-- Audit History -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Recent Audit</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">Audit trail for unit changes is available in the system audit logs.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('scripts')
<script>
    $(document).ready(function() {
        // Initialize any tooltips or interactions
        $('[data-bs-toggle="tooltip"]').tooltip();
    });
</script>
@endsection