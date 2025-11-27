import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OrderImportScheduledListComponent extends Component {
    @tracked scheduledImports = [
        {
            id: 1,
            name: 'wednesday imports',
            schedule: 'Once 11-26-2025 at 11:00 PM (GMT+01:00)'
        },
        {
            id: 2,
            name: 'wednesday imports 2',
            schedule: 'Once 11-26-2025 at 11:00 PM (GMT+01:00)'
        },
        {
            id: 3,
            name: 'wednesday imports 3',
            schedule: 'Once 11-26-2025 at 11:00 PM (GMT+01:00)'
        },
    ];

    @tracked completedImports = [
        {
            id: 4,
            name: 'test',
            schedule: 'Yearly at 10:00 PM (GMT+01:00)'
        },
        {
            id: 5,
            name: 'test 2',
            schedule: 'Yearly at 10:00 PM (GMT+01:00)'
        },
        {
            id: 6,
            name: 'test 3',
            schedule: 'Yearly at 10:00 PM (GMT+01:00)'
        },
    ];

    lastItemToDelete = null;

    @action
    handleDeleteTrigger() {
        // Check if itemToDelete has changed and we have an item to delete
        if (this.args.itemToDelete && this.args.itemToDelete !== this.lastItemToDelete) {
            this.lastItemToDelete = this.args.itemToDelete;
            // Perform the deletion
            this.deleteImport(this.args.itemToDelete);
        }
    }

    @action
    deleteImport(importItem) {
        // Remove from scheduled imports using object reference equality
        this.scheduledImports = this.scheduledImports.filter(item => item !== importItem);
        // Remove from completed imports using object reference equality
        this.completedImports = this.completedImports.filter(item => item !== importItem);

        // Call the parent onDelete callback if provided
        if (this.args.onDelete) {
            this.args.onDelete(importItem);
        }
    }
}
