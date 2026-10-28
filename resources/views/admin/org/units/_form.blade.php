@push('scripts')
<script>
    // Unit modal handling
    $(document).ready(function() {
        // Reset form on modal show
        $('#unitModal').on('hidden.bs.modal', function() {
            $(this).find('form')[0].reset();
            $(this).find('.form-control').parent().removeClass('has-error');
            $(this).find('small').text('');
        });
    });
</script>
@endpush

<div class="modal fade" id="unitModal" tabindex="-1" aria-labelledby="unitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="unitForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitFormLabel">Create Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="unitFormId">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="unitFormCode" class="form-label small">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="unitFormCode" name="code" required>
                        </div>
                        <div class="col-md-6">
                            <label for="unitFormName" class="form-label small">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="unitFormName" name="name" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="unitFormNameSw" class="form-label small">Name in Swahili</label>
                            <input type="text" class="form-control form-control-sm" id="unitFormNameSw" name="name_sw">
                        </div>
                        <div class="col-md-6">
                            <label for="unitFormUnitType" class="form-label small">Unit Type <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="unitFormUnitType" name="unit_type" required>
                                <option value="independent_unit">Independent Unit</option>
                                <option value="division">Division</option>
                                @foreach($departmentTypes as $dt)
                                    <option value="{{ $dt }}">{{ $dt }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="unitFormZoneId" class="form-label small">Zone <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="unitFormZoneId" name="zone_id" required>
                                <option value="">Select Zone</option>
                                @foreach($zones as $z)
                                    <option value="{{ $z->id }}">{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="unitFormSortOrder" class="form-label small">Sort Order</label>
                            <input type="number" class="form-control form-control-sm" id="unitFormSortOrder" name="sort_order" value="0">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="unitFormReportsToId" class="form-label small">Reports To (Parent Department)</label>
                            <select class="form-select form-select-sm" id="unitFormReportsToId" name="parent_department_id">
                                <option value="">None (Top-level)</option>
                                @foreach($parentDepartments as $pd)
                                    <option value="{{ $pd->id }}">{{ $pd->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="unitFormIsActive" class="form-label small">Active <span class="text-danger">*</span></label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="unitFormIsActive" checked required>
                                <label class="form-check-label" for="unitFormIsActive">
                                    Yes
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>