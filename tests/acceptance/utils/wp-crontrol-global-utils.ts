import { GlobalUtils, GlobalUtilsOptions } from '@johnbillion/plugin-infrastructure/acceptance';

export class CrontrolGlobalUtils extends GlobalUtils {
	constructor( options: GlobalUtilsOptions ) {
		super( options );
	}
	installWordPress() {
		// Call parent method for basic WordPress setup
		super.installWordPress();

		// Nothing custom here yet.
	}
}
