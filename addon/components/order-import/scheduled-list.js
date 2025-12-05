import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class OrderImportScheduledListComponent extends Component {
    @service scheduledImport;
    @service notifications;

    @tracked scheduledImports = [];
    @tracked completedImports = [];
    @tracked isLoading = false;

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task
    *loadData() {
        this.isLoading = true;
        try {
            // Fetch active scheduled imports
            const scheduled = yield this.scheduledImport.query({ status: 'active' });
            this.scheduledImports = scheduled;

            // Fetch completed/inactive scheduled imports (optional, depending on requirements)
            // For now, we'll just put everything else in completed or separate query
            // const completed = yield this.scheduledImport.query({ status: 'completed' });
            // this.completedImports = completed;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isLoading = false;
        }
    }

    lastItemToDelete = null;

    @action
    handleDeleteTrigger() {
        // Check if itemToDelete has changed and we have an item to delete
        if (this.args.itemToDelete && this.args.itemToDelete !== this.lastItemToDelete) {
            this.lastItemToDelete = this.args.itemToDelete;
            // Perform the deletion from the list (API call is done in parent)
            this.deleteImport(this.args.itemToDelete);
        }
    }

    @action
    deleteImport(importItem) {
        // Remove from scheduled imports using object reference equality or ID match
        this.scheduledImports = this.scheduledImports.filter(item => item.public_id !== importItem.public_id);
        // Remove from completed imports
        this.completedImports = this.completedImports.filter(item => item.public_id !== importItem.public_id);

        // Call the parent onDelete callback if provided
        if (this.args.onDelete) {
            this.args.onDelete(importItem);
        }
    }
}
