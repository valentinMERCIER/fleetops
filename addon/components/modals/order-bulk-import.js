import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ModalsOrderBulkImportComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;

    @tracked currentStep = 1;
    @tracked file;
    @tracked mappings = [];
    @tracked dryRunResults = [];
    @tracked importResult;
    @tracked isUploading = false;
    @tracked isProcessing = false;

    @tracked showScheduleModal = false;

    get title() {
        switch (this.currentStep) {
            case 1:
                return 'Import Orders';
            case 2:
                return 'Create Order Template';
            case 3:
                return 'Review Issues';
            case 4:
                return 'Import Complete';
            case 5:
                return 'Schedule Import';
            case 6:
                return 'Scheduled Imports';
            default:
                return 'Import Orders';
        }
    }

    get subTitle() {
        switch (this.currentStep) {
            case 1:
                return 'Choose a file to import orders';
            case 2:
                return 'Match the columns in your file to the corresponding order attributes';
            case 3:
                return 'Click Continue to import without editing. To correct issues click cancel and import again.';
            case 4:
                if (this.importResult) {
                    if (this.importResult.success === 0) {
                        return 'No orders imported';
                    } else if (this.importResult.success === 1) {
                        return '1 order imported successfully';
                    } else {
                        return `${this.importResult.success} orders imported successfully`;
                    }
                }
                return '';
            case 5:
                return 'Review the schedule';
            case 6:
                return 'Manage Scheduled Imports';
            default:
                return 'Import Orders';
        }
    }



    get nextButtonText() {
        switch (this.currentStep) {
            case 1:
                return 'Next';
            case 2:
                return 'Import';
            case 3:
                return 'Continue';
            case 4:
                return 'Done';
            case 5:
                return 'Schedule';
            default:
                return 'Next';
        }
    }

    get nextButtonScheme() {
        return 'primary';
    }

    get backButtonText() {
        return 'Back';
    }

    get showNextButton() {
        return this.currentStep !== 1; // Step 1 uses the "Choose file" button as the primary action trigger effectively, or we keep it hidden until file selected
    }

    @tracked csvHeaders = [];

    get systemFields() {
        return [
            {
                group: 'Order Details',
                fields: [
                    { label: 'Import ID (External ID)', key: 'import_id' },
                    { label: 'Internal ID', key: 'internal_id' },
                    { label: 'Order Type', key: 'type', required: true },
                    { label: 'Status', key: 'status' },
                    { label: 'Scheduled At', key: 'scheduled_at' },
                    { label: 'Customer', key: 'customer', required: true },
                    { label: 'Facilitator', key: 'facilitator' },
                    { label: 'Driver', key: 'driver' },
                    { label: 'Vehicle', key: 'vehicle' },
                    { label: 'Notes', key: 'notes' },
                ]
            },
            {
                group: 'Pickup',
                fields: [
                    { label: 'Pickup Name/ID', key: 'pickup' },
                    { label: 'Pickup Address', key: 'pickup_address', required: true },
                    { label: 'Pickup Phone', key: 'pickup_phone' },
                    { label: 'Pickup Contact Name', key: 'pickup_contact_name' },
                ]
            },
            {
                group: 'Dropoff',
                fields: [
                    { label: 'Dropoff Name/ID', key: 'dropoff' },
                    { label: 'Dropoff Address', key: 'dropoff_address', required: true },
                    { label: 'Dropoff Phone', key: 'dropoff_phone' },
                    { label: 'Dropoff Contact Name', key: 'dropoff_contact_name' },
                ]
            },
            {
                group: 'Return',
                fields: [
                    { label: 'Return Name/ID', key: 'return' },
                    { label: 'Return Address', key: 'return_address' },
                    { label: 'Return Phone', key: 'return_phone' },
                    { label: 'Return Contact Name', key: 'return_contact_name' },
                ]
            },
            {
                group: 'Settings & Options',
                fields: [
                    { label: 'Service Type', key: 'service_type' },
                    { label: 'POD Method', key: 'pod_method' },
                    { label: 'Adhoc (Yes/No)', key: 'adhoc' },
                    { label: 'Adhoc Distance', key: 'adhoc_distance' },
                    { label: 'Auto Dispatch (Yes/No)', key: 'dispatched' },
                    { label: 'POD Required (Yes/No)', key: 'pod_required' },
                ]
            },
            {
                group: 'Payload',
                fields: [
                    { label: 'Entities (Items)', key: 'entities' },
                ]
            }
        ];
    }

    @action
    async handleUpload(file) {
        this.file = file;
        this.isUploading = true;

        try {
            const content = await file.readAsText();
            const lines = content.split(/\r\n|\n/);
            if (lines.length > 0) {
                const headerLine = lines[0];
                this.csvHeaders = headerLine.split(',').map(h => h.trim());

                // Initialize mappings
                this.mappings = this.systemFields.map(group => {
                    return {
                        group: group.group,
                        fields: group.fields.map(field => {
                            const match = this.csvHeaders.find(h => h.toLowerCase() === field.key.toLowerCase() || h.toLowerCase() === field.label.toLowerCase());
                            return {
                                ...field,
                                selectedColumn: match || ''
                            };
                        })
                    };
                });
            }
            this.isUploading = false;
            this.nextStep();
        } catch (error) {
            this.isUploading = false;
            this.notifications.error('Could not read file content: ' + error.message);
        }
    }

    @tracked selectedImport = null;
    @tracked scheduleSettings = {
        name: 'New Scheduled Import'
    };
    @tracked previousStep = null; // Track where we came from for back navigation
    @tracked itemToDelete = null; // Store the item to delete
    @tracked isEditingSchedule = false; // Track if we're editing an existing schedule


    @action
    viewScheduledImports() {
        this.previousStep = this.currentStep;
        this.currentStep = 6;
    }

    @action
    selectImport(importItem) {
        this.selectedImport = importItem;
    }

    @action
    editSelectedImport() {
        if (this.selectedImport) {
            // Pre-fill the schedule settings with the selected import's data
            this.scheduleSettings = {
                name: this.selectedImport.name
                // TODO: Add other fields once API provides full data
                // startDate, frequency, period, days, time, timezone, ends
            };
            // Navigate to schedule settings step to edit the selected import
            this.previousStep = 6; // Remember we came from scheduled imports
            this.isEditingSchedule = true;
            this.currentStep = 5;
        }
    }

    @action
    deleteSelectedImport() {
        if (this.selectedImport) {
            // Store the item to delete and pass it to the child component
            this.itemToDelete = this.selectedImport;
            this.notifications.success('Deleted import: ' + this.selectedImport.name);
            this.selectedImport = null;
        }
    }

    @action
    updateScheduleName(name) {
        this.scheduleSettings.name = name;
    }

    @action
    scheduleImport() {
        this.isEditingSchedule = false;
        this.currentStep = 5;
    }

    @action
    saveSchedule() {
        // Placeholder for saving schedule
        this.notifications.success('Import scheduled successfully');
        // Navigate to scheduled imports instead of closing
        this.currentStep = 6;
    }

    @action
    reset() {
        this.currentStep = 1;
        this.file = null;
        this.mappings = [];
        this.importResult = null;
    }

    @action
    nextStep() {
        if (this.currentStep === 2) {
            // Simulate dry run / validation
            this.currentStep = 3;
            this.dryRunResults = [
                { row: 2, column: 'completeBefore', message: 'Invalid date range - completeBefore or completeAfter is in the past' },
                { row: 3, column: 'completeBefore', message: 'Invalid date range - completeBefore or completeAfter is in the past' },
            ];
        } else if (this.currentStep === 3) {
            this.executeImport.perform();
        } else if (this.currentStep === 5) {
            this.saveSchedule();
        } else if (this.currentStep < 4) {
            this.currentStep++;
        } else {
            this.args.onConfirm();
        }
    }

    @action
    prevStep() {
        // If we're on step 5 and came from step 6 (scheduled imports), go back to step 6
        if (this.currentStep === 5 && this.previousStep === 6) {
            this.currentStep = 6;
            this.previousStep = null;
            this.selectedImport = null; // Clear selection when going back
            this.isEditingSchedule = false; // Reset editing state
        } else if (this.currentStep > 1) {
            this.currentStep--;
        }
    }

    @action
    updateMapping(map, event) {
        set(map, 'selectedColumn', event.target.value);
    }

    @task
    * executeImport() {
        this.isProcessing = true;
        try {
            // Simulate API call
            yield new Promise((resolve) => setTimeout(resolve, 1500));
            this.importResult = { success: 10, failed: 0 };
            this.currentStep = 4;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isProcessing = false;
        }
    }
}
