@extends('layouts.app')

@section('content')
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="page-header d-flex justify-between align-center">
                <span>Divisions</span>
                <div>
                    <button class="btn btn-primary btn-sm" id="createDivisionBtn">
                        <i class="fas fa-plus me-2"></i> Create Division
                    </button>
                </div>
            </h2>

            <!-- Search and Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form id="divisionFiltersForm">
                        <div class="row g-3">
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Search</label>
                                <input type="text" class="form-control form-control-sm" id="divisionSearch" placeholder="Name or code...">
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Parent Department</label>
                                <select class="form-select form-select-sm" id="parentDepartmentFilter">
                                    <option value="">All Departments</option>
                                    @foreach($parentDepartments as $pd)
                                        <option value="{{ $pd->id }}">{{ $pd->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small">Zone</label>
                                <select class="form-select form-select-sm" id="zoneFilter">
                                    <option value="">All Zones</option>
                                    @foreach($zones as $z)
                                        <option value="{{ $z->id }}">{{ $z->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-2">
                                <label class="form-label small">Status</label>
                                <select class="form-select form-select-sm" id="statusFilter">
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
                    <span>Divisions List</span>
                    <span class="text-muted small" id="divisionRecordCount">Loading...</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Parent Department</th>
                                    <th>Zone</th>
                                    <th>Head</th>
                                    <th>Active Employees</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="divisionTableBody">
                                @if($divisions->isEmpty())
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No divisions found</td>
                                    </tr>
                                @else
                                    @foreach($divisions as $d)
                                        <tr>
                                            <td>{{ $d->code ?? '—' }}</td>
                                            <td>
                                                <a href="{{ route('divisions.show', $d->id) }}" class="text-primary">{{ $d->name }}</a>
                                            </td>
                                            <td>
                                                @if($d->parent_department_id)
                                                    {{ Department::find($d->parent_department_id)->name ?? '—' }}
                                                @else
                                                    <span class="text-success">Root</span>
                                                @endif
                                            </td>
                                            <td>{{ $d->zone?->name ?? '—' }}</td>
                                            <td>
                                                @if($d->headGrant)
                                                    {{ $d->headGrant->employee?->full_name ?? '—' }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>{{ $d->active_employees_count ?? 0 }}</td>
                                            <td>
                                                @if($d->is_active)
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
                                                    @can('divisions.update')
                                                        <button class="btn btn-outline-warning" title="Edit" onclick="editDivision({{ $d->id }})">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    @endif
                                                    @can('divisions.assign_head')
                                                        <button class="btn btn-outline-info" title="Assign Head" onclick="assignDivisionHead({{ $d->id }})">
                                                            <i class="fas fa-user-tie"></i>
                                                        </button>
                                                    @endif
                                                    @can('divisions.delete')
                                                        <button class="btn btn-outline-danger" title="Archive" onclick="confirmArchiveDivision({{ $d->id }})">
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
            @if($divisions->hasPages())
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center small">
                        @if($divisions->onFirstPage())
                            <li class="page-item disabled">
                                <span class="page-link">Previous</span>
                            </li>
                        @else]
                            <li class="page-item">
                                <a class="page-link" href="javascript:;;" onclick="loadDivisions({{$divisions->previousPageUrl()}})" aria-label="Previous">
                                    <span aria-hidden="true">«</span>
                                </a>
                            </li>
                        @endif]
                        @foreach($divisions->elements() as $element)
                            @if($element !== null)
                                @if(is_string($element))
                                    <li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>
                                @else]
                                    <li class="page-item {{ $element->isCurrentPage() ? 'active' : '' }}">
                                        <a class="page-link" href="javascript:;;" onclick="loadDivisions({{$element->url()}})">{{ $element->number() }}</a>
                                    </li>
                                @endif]
                            @endif
                        @endforeach]
                        @if($divisions->onLastPage())
                            <li class="page-item disabled">
                                <span class="page-link">Next</span>
                            </li>
                        @else]
                            <li class="page-item">
                                <a class="page-link" href="javascript:;;" onclick="loadDivisions({{$divisions->nextPageUrl()}})" aria-label="Next">
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
    let currentDivisionPage = 1;

    // Load divisions with optional page
    function loadDivisions(pageUrl = null) {
        const search = $('#divisionSearch').val() || '';
        const parentId = $('#parentDepartmentFilter').val() || '';
        const zoneId = $('#zoneFilter').val() || '';
        const status = $('#statusFilter').val() || '';

        $.ajax({
            url: '/api/org/divisions?' + (pageUrl || 'page=1'),
            type: 'GET',
            dataType: 'json',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Accept', 'application/json');
            },
            success: function(response) {
                const tbody = $('#divisionTableBody');
                tbody.empty();

                if (response.data && response.data.length > 0) {
                    $.each(response.data, function(index, division) {
                        const statusBadge = division.is_active
                            ? '<span class="badge bg-success">Active</span>'
                            : '<span class="badge bg-secondary">Archived</span>';

                        const headName = division.head
                            ? division.head.full_name
                            : '—';

                        const tr = $('<tr>').append(
                            $('<td>').text(division.code),
                            $('<td>')
                                .html('<a href="' + route('divisions.show', division.id) + '" class="text-primary">' + division.name + '</a>')
                                ,
                            $('<td>').text(division.parent_department || '—'),
                            $('<td>').text(division.zone || '—'),
                            $('<td>').text(headName),
                            $('<td>').text(division.active_employees_count || 0),
                            $('<td>').html(statusBadge),
                            $('<td>')
                                .html('<button class="btn btn-outline-primary btn-sm" title="View"><i class="fas fa-eye"></i></button>' +
                                    '@can(\"divisions.update\") ? '<button class="btn btn-outline-warning btn-sm" title="Edit" onclick="editDivision(' + division.id + ')"><i class="fas fa-edit"></i></button>' : '' +
                                    '@can(\"divisions.assign_head\") ? '<button class="btn btn-outline-info btn-sm" title="Assign Head" onclick="assignDivisionHead(' + division.id + ')"><i class="fas fa-user-tie"></i></button>' : '' +
                                    '@can(\"divisions.delete\") ? '<button class="btn btn-outline-danger btn-sm" title="Archive" onclick="confirmArchiveDivision(' + division.id + ')"><i class="fas fa-archive"></i></button>' : ''
                                ')
                        );

                        tbody.append(tr);
                    });
                } else {
                    trow = $('<tr>').html('<td colspan="8" class="text-center text-muted py-4">No divisions found</td>');
                    tbody.append(trow);
                }

                // Update pagination
                currentDivisionPage = response.pagination.current_page || 1;
                updatePaginationControls(response.pagination);

                // Update record count
                $('#divisionRecordCount').text(
                    response.pagination.total + ' divisions'
                );
            },
            error: function(xhr, status, error) {
                console.error('Error loading divisions:', error);
                alert('Failed to load divisions. Please try again.');
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
            $pagination.append(`<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadDivisions('${pagination.prevPageUrl()}')" aria-label="Previous"><span aria-hidden="true">«</span></a></li>`);
        } else {
            $pagination.append('<li class="page-item disabled"><span class="page-link">Previous</span></li>');
        }

        // Pages
        for (let i = 1; i <= totalPages; i++) {
            if (i === pagination.current_page) {
                $pagination.append('<li class="page-item active"><span class="page-link">' + i + '</span></li>');
            } else {
                $pagination.append('<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadDivisions(\'page/' + i + '\')">' + i + '</a></li>');
            }
        }

        // Next
        if (pagination.current_page < totalPages) {
            $pagination.append(`<li class="page-item"><a class="page-link" href="javascript:;;" onclick="loadDivisions('${pagination.nextPageUrl()}')" aria-label="Next"><span aria-hidden="true">»</span></a></li>`);
        } else {
            $pagination.append('<li class="page-item disabled"><span class="page-link">Next</span></li>');
        }
    }

    // Create division
    function createDivision() {
        $('#divisionForm').trigger('reset');
        $('#divisionFormLabel').text('Create Division');
        $('#divisionFormId').val('');
        $('#divisionModal').modal('show');
    }

    // Edit division
    function editDivision(id) {
        $.ajax({
            url: '/api/org/divisions/' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                const division = response.data;
                $('#divisionFormId').val(division.id);
                $('#divisionFormCode').val(division.code || '');
                $('#divisionFormName').val(division.name);
                $('#divisionFormNameSw').val(division.name_sw || '');
                $('#divisionFormZoneId').val(division.zone_id || '');
                $('#divisionFormParentDepartmentId').val(division.parent_department_id || '');
                $('#divisionFormIsActive').prop('checked', division.is_active);
                $('#divisionFormSortOrder').val(division.sort_order || 0);
                $('#divisionFormLabel').text('Edit Division');
                $('#divisionModal').modal('show');
            },
            error: function(xhr, status, error) {
                console.error('Error loading division:', error);
                alert('Failed to load division details.');
            }
        });
    }

    // Assign division head
    function assignDivisionHead(id) {
        $.ajax({
            url: '/api/org/divisions/' + id + '/head',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                const head = response.data;
                $('#assignHeadDivisionId').val(id);
                $('#assignHeadEmployeeId').val(head.employee_id || '');
                $('#assignHeadEmployeeName').val(head.full_name || '');
                $('#assignHeadModal').modal('show');
            },
            error: function(xhr, status, error) {
                console.error('Error loading division head:', error);
                alert('Failed to load head information.');
            }
        });
    }

    // Confirm archive division
    function confirmArchiveDivision(id) {
        if (confirm('Are you sure you want to archive this division? This will soft-delete it (is_active = false). Division will be hidden from lists but can be unarchived.')) {
            archiveDivision(id);
        }
    }

    // Archive division
    function archiveDivision(id) {
        $.ajax({
            url: '/api/org/divisions/' + id,
            type: 'DELETE',
            dataType: 'json',
            success: function(response) {
                alert('Division archived successfully.');
                loadDivisions();
            },
            error: function(xhr, status, error) {
                console.error('Error archiving division:', error);
                if (xhr.status === 422) {
                    alert('Cannot archive division: ' + xhr.responseJSON.message);
                } else {
                    alert('Failed to archive division. Please try again.');
                }
            }
        });
    }

    // Handle form submit
    $('#divisionForm').on('submit', function(e) {
        e.preventDefault();

        const formData = {
            code: $('#divisionFormCode').val(),
            name: $('#divisionFormName').val(),
            name_sw: $('#divisionFormNameSw').val(),
            zone_id: $('#divisionFormZoneId').val(),
            parent_department_id: $('#divisionFormParentDepartmentId').val(),
            is_active: $('#divisionFormIsActive').is(':checked'),
            sort_order: $('#divisionFormSortOrder').val(),
        };

        const isEdit = $('#divisionFormId').val() !== '';
        const method = isEdit ? 'PATCH' : 'POST';
        const url = isEdit ? '/api/org/divisions/' + $('#divisionFormId').val() : '/api/org/divisions';

        $.ajax({
            url: url,
            type: method,
            data: formData,
            dataType: 'json',
            success: function(response) {
                $('#divisionModal').modal('hide');
                alert(isEdit ? 'Division updated successfully.' : 'Division created successfully.');
                loadDivisions();
            },
            error: function(xhr, status, error) {
                console.error('Error saving division:', error);
                if (xhr.status === 422) {
                    // Display validation errors
                    const errors = xhr.responseJSON.errors;
                    if (errors.code) $('#divisionFormCode').parent().addClass('has-error').find('small').text(errors.code);
                    if (errors.name) $('#divisionFormName').parent().addClass('has-error').find('small').text(errors.name);
                    if (errors.zone_id) $('#divisionFormZoneId').parent().addClass('has-error').find('small').text(errors.zone_id);
                    if (errors.parent_department_id) $('#divisionFormParentDepartmentId').parent().addClass('has-error').find('small').text(errors.parent_department_id);
                } else {
                    alert('Failed to save division. Please try again.');
                }
            }
        });
    });

    // Initial load
    $(document).ready(function() {
        loadDivisions();
    });
</script>
@endsection