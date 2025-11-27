import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsOrdersImportController extends Controller {
    @tracked currentScreen = 1;
    @tracked isUploading = false;
    @tracked uploadData = null;
    @tracked mapping = [];
    @tracked validationIssues = [];
    @tracked importResult = null;

    @action
    nextScreen() {
        this.currentScreen++;
    }

    @action
    prevScreen() {
        if (this.currentScreen > 1) {
            this.currentScreen--;
        }
    }

    @action
    setScreen(screen) {
        this.currentScreen = screen;
    }

    @action
    handleUpload(file) {
        this.isUploading = true;
        // Simulate upload for now
        setTimeout(() => {
            this.isUploading = false;
            this.uploadData = file;
            this.nextScreen();
        }, 1000);
    }
}
