@push('scripts')
<script>
    // Division modal handling
    $(document).ready(function() {
        // Reset form on modal show
        $('#divisionModal').on('hidden.bs.modal', function() {
            $(this).find('form')[0].reset();
            $(this).find('.form-control').parent().removeClass('has-error');
            $(this).find('small').text('');
        });
    });
</script>
@endpush

<div class="modal fade" id="divisionModal" tabindex="-1" aria-labelledby="divisionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="divisionForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="divisionFormLabel">Create Division</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="divisionFormId">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="divisionFormCode" class="form-label small">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="divisionFormCode" name="code" required>
                        </div>
                        <div class="col-md-6">
                            <label for="divisionFormName" class="form-label small">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="divisionFormName" name="name" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="divisionFormNameSw" class="form-label small">Name in Swahili</label>
                            <input type="text" class="form-control form-control-sm" id="divisionFormNameSw" name="name_sw">
                        </div>
                        <div class="col-md-6">
                            <label for="divisionFormZoneId" class="form-label small">Zone <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="divisionFormZoneId" name="zone_id" required>
                                <option value="">Select Zone</option>
                                @foreach($zones as $z)
                                    <option value="{{ $z->id }}">{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="divisionFormParentDepartmentId" class="form-label small">Parent Department <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="divisionFormParentDepartmentId" name="parent_department_id" required>
                                <option value="">Select Parent Department</option>
                                @foreach($parentDepartments as $pd)
                                    <option value="{{ $pd->id }}">{{ $pd->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="divisionFormSortOrder" class="form-label small">Sort Order</label>
                            <input type="number" class="form-control form-control-sm" id="divisionFormSortOrder" name="sort_order" value="0">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label for="divisionFormIsActive" class="form-label small">Active <span class="text-danger">*</span></label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="divisionFormIsActive" checked required>
                                <label class="form-check-label" for="divisionFormIsActive">
                                    Yes
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Division</button>
                </div>
            </form>
        </div>
    </div>
</div>