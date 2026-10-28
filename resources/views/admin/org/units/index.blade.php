@extends('layouts.app')

@section('content')
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="page-header d-flex justify-between align-center">
                <span>Independent Units</span>
                <div>
                    <button class="btn btn-primary btn-sm" id="createUnitBtn">
                        <i class="fas fa-plus me-2"></i> Create Unit
                    </button>
                </div>
            </h2>

            <!-- Search and Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form id="unitFiltersForm">
                        <div class="row g-3">
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Search</label>
                                <input type="text" class="form-control form-control-sm" id="unitSearch" placeholder="Name or code...">
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Zone</label>
                                <select class="form-select form-select-sm" id="unitZoneFilter">
                                    <option value="">All Zones</option>
                                    @foreach($zones as $z)
                                        <option value="{{ $z->id }}">{{ $z->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Status</label>
                                <select class="form-select form-select-sm" id="unitStatusFilter">
                                    <option value="">All</option>
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-link text-primary w-100 small">Apply Filters</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header d-flex justify-between align-center">
                    <span>Independent Units List</span>
                    <span class="text-muted small" id="unitRecordCount">Loading...</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Unit Type</th>
                                    <th>Reports To</th>
                                    <th>Zone</th>
                                    <th>Head</th>
                                    <th>Active Employees</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="unitTableBody">
                                @if($units->isEmpty())
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No independent units found</td>
                                    </tr>
                                @else
                                    @foreach($units as $u)
                                        <tr>
                                            <td>{{ $u->code ?? '—' }}</td>
                                            <td>
                                                <a href="{{ route('units.show', $u->id) }}" class="text-primary">{{ $u->name }}</a>
                                            </td>
                                            <td>
                                                @if($u->unit_type === 'independent_unit')
                                                    Independent Unit
                                                @else
                                                    {{ $u->unit_type }}
                                                @endif
                                            </td>
                                            <td>
                                                @if($u->parent_department_id)
                                                    {{ Department::find($u->parent_department_id)->name ?? '—' }}
                                                @else
                                                    <span class="text-success">Root</span>
                                                @endif
                                            </td>
                                            <td>{{ $u->zone?->name ?? '—' }}</td>
                                            <td>
                                                @if($u->headGrant)
                                                    {{ $u->headGrant->employee?->full_name ?? '—' }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>{{ $u->active_employees_count ?? 0 }}</td>
                                            <td>
                                                @if($u->is_active)
                                                    <span class="badge bg-success">Active</span>
                                                @else
                                                    <span class="badge bg-secondary">Archived</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    @can('units.update')
                                                        <button class="btn btn-outline-warning" title="Edit" onclick="editUnit({{ $u->id }})">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    @endif
                                                    @can('units.assign_head')
                                                        <button class="btn btn-outline-info" title="Assign Head" onclick="assignUnitHead({{ $u->id }})">
                                                            <i class="fas fa-user-tie"></i>
                                                        </button>
                                                    @endif
                                                    @can('units.delete')
                                                        <button class="btn btn-outline-danger" title="Archive" onclick="confirmArchiveUnit({{ $u->id }})">
                                                            <i class="fas fa-archive"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            @if($units->hasPages())
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center small">
                        @if($units->onFirstPage())
                            <li class="page-item disabled">
                                <span class="page-link">Previous</span>
                            </li>
                        @else]
                            <li class="page-item">
                                <a class="page-link" href="javascript:;;" onclick="loadUnits({{$units->previousPageUrl()}})" aria-label="Previous">
                                    <span aria-hidden="true">«</span>
                                </a>
                            </li>
                        @endif]
                        @foreach($units->elements() as $element)
                            @if($element !== null)
                                @if(is_string($element))
                                    <li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>
                                @else]
                                    <li class="page-item {{ $element->isCurrentPage() ? 'active' : '' }}">
                                        <a class="page-link" href="javascript:;;" onclick="loadUnits({{$element->url()}})">{{ $element->number() }}</a>
                                    </li>
                                @endif]
                            @endif
                        @endforeach]
                        @if($units->onLastPage())
                            <li class="page-item disabled">
                                <span class="page-link">Next</span>
                            </li>
                        @else]
                            <li class="page-item">
                                <a class="page-link" href="javascript:;;" onclick="loadUnits({{$units->nextPageUrl()}})" aria-label="Next">
                                    <span aria-hidden="true">»</span>
                                </a>
                            </li>
                        @endif]
                        @endif
                    </ul>
                </nav>
            @endif
        </div>
    </div>
</div>

@section('scripts')
<script>
    let currentUnitPage = 1;

    // Load units with optional page
    function loadUnits(pageUrl = null) {
        const search = $('#unitSearch').val() || '';
        const zoneId = $('#unitZoneFilter').val() || '';
        const status = $('#unitStatusFilter').val() || '';

        $.ajax({
            url: '/api/org/units?' + (pageUrl || 'page=1'),
            type: 'GET',
            dataType: 'json',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Accept', 'application/json');
            },
            success: function(response) {
                const tbody = $('#unitTableBody');
                tbody.empty();

                if (response.data && response.data.length > 0) {
                    $.each(response.data, function(index, unit) {
                        const statusBadge = unit.is_active
                            ? '<span class="badge bg-success">Active</span>'
                            : '<span class="badge bg-secondary">Archived</span>';

                        const reportsTo = unit.reports_to === '—' ? '—' : unit.reports_to;
                        const headName = unit.head
                            ? unit.head.full_name
                            : '—';

                        const tr = $('<tr>').append(
                            $('<td>').text(unit.code),
                            $('<td>')
                                .html('<a href="' + route('units.show', unit.id) + '" class="text-primary">' + unit.name + '</a>')
                                ,
                            $('<td>').text(unit.unit_type),
                            $('<td>').text(reportsTo),
                            $('<td>').text(unit.zone || '—'),
                            $('<td>').text(headName),
                            $('<td>').text(unit.active_employees_count || 0),
                            $('<td>').html(statusBadge),
                            $('<td>')
                                .html('<button class="btn btn-outline-primary btn-sm" title="View"><i class="fas fa-eye"></i></button>' +
                                    '@can(\"units.update\") ? '<button class="btn btn-outline-warning btn-sm" title="Edit" onclick="editUnit(' + unit.id + ')"><i class="fas fa-edit"></i></button>' : '' +
                                    '@can(\"units.assign_head\") ? '<button class="btn btn-outline-info btn-sm" title="Assign Head" onclick="assignUnitHead(' + unit.id + ')"><i class="fas fa-user-tie"></i></button>' : '' +
                                    '@can(\"units.delete\") ? '<button class="btn btn-outline-danger btn-sm" title="Archive" onclick="confirmArchiveUnit(' + unit.id + ')"><i class="fas fa-archive"></i></button>' : ''
                                )
                        );

                        tbody.append(tr);
                    });
                } else {
                    trow = $('<tr>').html('<td colspan="9" class="text-center text-muted py-4">No independent units found</td>');
                    tbody.append(trow);
                }

                // Update pagination
                currentUnitPage = response.pagination.current_page || 1;
                updatePaginationControls(response.pagination);

                // Update record count
                $('#unitRecordCount').text(
                    response.pagination.total + ' independent units'
                );
            },
            error: function(xhr, status, error) {
                console.error('Error loading units:', error);
                alert('Failed to load units. Please try again.');
            }
        });
    }

    // Update pagination controls
    function updatePaginationControls(pagination) {
        const totalPages = pagination.last_page || 1;
        const $pagination = $('.pagination');

        $pagination.empty();

        if (totalPages <= 1) return;

        // Previous
        if (pagination.current_page > 1) {
            $pagination.append(`<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadUnits('${pagination.prevPageUrl()}')" aria-label="Previous"><span aria-hidden="true">«</span></a></li>`);
        } else {
            $pagination.append('<li class="page-item disabled"><span class="page-link">Previous</span></li>');
        }

        // Pages
        for (let i = 1; i <= totalPages; i++) {
            if (i === pagination.current_page) {
                $pagination.append('<li class="page-item active"><span class="page-link">' + i + '</span></li>');
            } else {
                $pagination.append('<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadUnits(\'page/' + i + '\')">' + i + '</a></li>');
            }
        }

        // Next
        if (pagination.current_page < totalPages) {
            $pagination.append(`<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadUnits('${pagination.nextPageUrl()}')" aria-label="Next"><span aria-hidden="true">»</span></a></li>`);
        } else {
            $pagination.append('<li class="page-item disabled"><span class="page-link">Next</span></li>');
        }
    }

    // Create unit
    function createUnit() {
        $('#unitForm').trigger('reset');
        $('#unitFormLabel').text('Create Unit');
        $('#unitFormId').val('');
        $('#unitModal').modal('show');
    }

    // Edit unit
    function editUnit(id) {
        $.ajax({
            url: '/api/org/units/' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                const unit = response.data;
                $('#unitFormId').val(unit.id);
                $('#unitFormCode').val(unit.code || '');
                $('#unitFormName').val(unit.name);
                $('#unitFormNameSw').val(unit.name_sw || '');
                $('#unitFormUnitType').val(unit.unit_type);
                $('#unitFormZoneId').val(unit.zone_id || '');
                $('#unitFormReportsToId').val(unit.parent_department_id || '');
                $('#unitFormIsActive').prop('checked', unit.is_active);
                $('#unitFormSortOrder').val(unit.sort_order || 0);
                $('#unitFormLabel').text('Edit Unit');
                $('#unitModal').modal('show');
            },
            error: function(xhr, status, error) {
                console.error('Error loading unit:', error);
                alert('Failed to load unit details.');
            }
        });
    }

    // Assign unit head
    function assignUnitHead(id) {
        $.ajax({
            url: '/api/org/units/' + id + '/head',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                const head = response.data;
                $('#assignHeadUnitId').val(id);
                $('#assignHeadEmployeeId').val(head.employee_id || '');
                $('#assignHeadEmployeeName').val(head.full_name || '');
                $('#assignHeadModal').modal('show');
            },
            error: function(xhr, status, error) {
                console.error('Error loading unit head:', error);
                alert('Failed to load head information.');
            }
        });
    }

    // Confirm archive unit
    function confirmArchiveUnit(id) {
        if (confirm('Are you sure you want to archive this independent unit? This will soft-delete it (is_active = false). Unit will be hidden from lists but can be unarchived.')) {
            archiveUnit(id);
        }
    }

    // Archive unit
    function archiveUnit(id) {
        $.ajax({
            url: '/api/org/units/' + id,
            type: 'DELETE',
            dataType: 'json',
            success: function(response) {
                alert('Unit archived successfully.');
                loadUnits();
            },
            error: function(xhr, status, error) {
                console.error('Error archiving unit:', error);
                if (xhr.status === 422) {
                    alert('Cannot archive unit: ' + xhr.responseJSON.message);
                } else {
                    alert('Failed to archive unit. Please try again.');
                }
            }
        });
    }

    // Handle form submit
    $('#unitForm').on('submit', function(e) {
        e.preventDefault();

        const formData = {
            code: $('#unitFormCode').val(),
            name: $('#unitFormName').val(),
            name_sw: $('#unitFormNameSw').val(),
            unit_type: $('#unitFormUnitType').val(),
            zone_id: $('#unitFormZoneId').val(),
            parent_department_id: $('#unitFormReportsToId').val(),
            is_active: $('#unitFormIsActive').is(':checked'),
            sort_order: $('#unitFormSortOrder').val(),
        };

        const isEdit = $('#unitFormId').val() !== '';
        const method = isEdit ? 'PATCH' : 'POST';
        const url = isEdit ? '/api/org/units/' + $('#unitFormId').val() : '/api/org/units';

        $.ajax({
            url: url,
            type: method,
            data: formData,
            dataType: 'json',
            success: function(response) {
                $('#unitModal').modal('hide');
                alert(isEdit ? 'Unit updated successfully.' : 'Unit created successfully.');
                loadUnits();
            },
            error: function(xhr, status, error) {
                console.error('Error saving unit:', error);
                if (xhr.status === 422) {
                    // Display validation errors
                    const errors = xhr.responseJSON.errors;
                    if (errors.code) $('#unitFormCode').parent().addClass('has-error').find('small').text(errors.code);
                    if (errors.name) $('#unitFormName').parent().addClass('has-error').find('small').text(errors.name);
                    if (errors.unit_type) $('#unitFormUnitType').parent().addClass('has-error').find('small').text(errors.unit_type);
                    if (errors.zone_id) $('#unitFormZoneId').parent().addClass('has-error').find('small').text(errors.zone_id);
                    if (errors.parent_department_id) $('#unitFormReportsToId').parent().addClass('has-error').find('small').text(errors.parent_department_id);
                } else {
                    alert('Failed to save unit. Please try again.');
                }
            }
        });
    });

    // Initial load
    $(document).ready(function() {
        loadUnits();
    });
</script>
@endsection