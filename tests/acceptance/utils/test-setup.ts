import { test as base, expect, Page } from '@playwright/test';
import { CrontrolUtils } from './wp-crontrol';
import { CrontrolGlobalUtils } from './wp-crontrol-global-utils';
import * as fs from 'fs';
import * as path from 'path';

class Admin {
	private page: Page;

	constructor( page: Page ) {
		this.page = page;
	}

	async visitAdminPage( path: string = '', queryString: string = '' ) {
		const url = `/wp-admin/${path}${queryString ? '?' + queryString : ''}`;
		await this.page.goto( url );
	}
}

type CrontrolFixtures = {
	admin: Admin;
	Crontrol: CrontrolUtils;
	globalUtils: CrontrolGlobalUtils;
};

export const test = base.extend<CrontrolFixtures>( {
	admin: async ( { page }, use ) => {
		const admin = new Admin( page );
		await use( admin );
	},
	Crontrol: async ( { page, admin, globalUtils }, use ) => {
		const Crontrol = new CrontrolUtils( page, admin, globalUtils );
		await use( Crontrol );
	},
	globalUtils: async ( {}, use, testInfo ) => {
		const baseURL = testInfo.project.use.baseURL!;
		const globalUtils = new CrontrolGlobalUtils( { baseURL, pluginSlug: 'wp-crontrol' } );
		await use( globalUtils );
	},
} );

// Add automatic HTML capture on failure
test.afterEach(async ({ page }, testInfo) => {
	if (testInfo.status !== 'passed') {
		// Capture the page HTML content
		const html = await page.content();
		const htmlPath = path.join(testInfo.outputDir, 'page-content.html');
		fs.writeFileSync(htmlPath, html, 'utf8');
		testInfo.attachments.push({
			name: 'page-html',
			path: htmlPath,
			contentType: 'text/html',
		});
	}
});

export { expect };
