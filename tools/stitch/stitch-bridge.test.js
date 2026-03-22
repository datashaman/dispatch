/**
 * Tests for stitch-bridge.js
 *
 * Uses Node.js built-in test runner (node --test).
 * Tests argument parsing, validation, and output format
 * without requiring real Stitch API access.
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const execFileAsync = promisify(execFile);
const __dirname = dirname(fileURLToPath(import.meta.url));
const BRIDGE = join(__dirname, 'stitch-bridge.js');

/**
 * Helper to run the bridge script with args and optional env overrides.
 * Returns { stdout, stderr, exitCode }.
 */
async function runBridge(args = [], env = {}) {
  try {
    const { stdout, stderr } = await execFileAsync('node', [BRIDGE, ...args], {
      env: { ...process.env, ...env },
      timeout: 10000,
    });
    return { stdout, stderr, exitCode: 0 };
  } catch (error) {
    return {
      stdout: error.stdout || '',
      stderr: error.stderr || '',
      exitCode: error.code || 1,
    };
  }
}

// -- Argument parsing and validation tests --

describe('stitch-bridge CLI validation', () => {
  it('exits with usage when no command given', async () => {
    const { stderr, exitCode } = await runBridge([], { STITCH_API_KEY: 'test' });
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /Usage:/);
  });

  it('exits with error when STITCH_API_KEY is missing', async () => {
    const { stderr, exitCode } = await runBridge(
      ['generate', '--project', 'proj_123', '--prompt', 'test'],
      { STITCH_API_KEY: '' }
    );
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /STITCH_API_KEY/);
  });

  it('exits with error for unknown command', async () => {
    const { stderr, exitCode } = await runBridge(['foobar'], { STITCH_API_KEY: 'test' });
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /Unknown command/);
  });

  it('exits with error when generate is missing --project', async () => {
    const { stderr, exitCode } = await runBridge(
      ['generate', '--prompt', 'test'],
      { STITCH_API_KEY: 'test' }
    );
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /--project/i);
  });

  it('exits with error when generate is missing --prompt', async () => {
    const { stderr, exitCode } = await runBridge(
      ['generate', '--project', 'proj_123'],
      { STITCH_API_KEY: 'test' }
    );
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /--prompt/i);
  });

  it('exits with error when edit is missing --screen', async () => {
    const { stderr, exitCode } = await runBridge(
      ['edit', '--prompt', 'test'],
      { STITCH_API_KEY: 'test' }
    );
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /--screen/i);
  });

  it('exits with error when edit is missing --prompt', async () => {
    const { stderr, exitCode } = await runBridge(
      ['edit', '--screen', 'scr_123'],
      { STITCH_API_KEY: 'test' }
    );
    assert.notEqual(exitCode, 0);
    assert.match(stderr, /--prompt/i);
  });
});

// -- Marker format tests (for coder agent extraction) --

describe('marker format for upstream output', () => {
  /**
   * The designer agent outputs markers that the coder agent extracts via regex.
   * These tests validate the regex patterns work on the expected marker format.
   */

  const SCREENSHOT_REGEX = /<!-- STITCH_SCREENSHOT: (.+?) -->/;
  const HTML_REGEX = /<!-- STITCH_HTML_START -->\n([\s\S]*?)\n<!-- STITCH_HTML_END -->/;

  it('extracts screenshot URL from marker', () => {
    const output = `Here is the design I generated:

<!-- STITCH_SCREENSHOT: https://stitch.googleapis.com/screenshots/scr_abc123.png -->

<!-- STITCH_HTML_START -->
<html><body><h1>Login Form</h1></body></html>
<!-- STITCH_HTML_END -->`;

    const screenshotMatch = output.match(SCREENSHOT_REGEX);
    assert.ok(screenshotMatch, 'Screenshot marker should match');
    assert.equal(screenshotMatch[1], 'https://stitch.googleapis.com/screenshots/scr_abc123.png');
  });

  it('extracts HTML content from markers', () => {
    const output = `Here is the design I generated:

<!-- STITCH_SCREENSHOT: https://example.com/img.png -->

<!-- STITCH_HTML_START -->
<html>
  <head><title>Dashboard</title></head>
  <body>
    <div class="container">
      <h1>Analytics Dashboard</h1>
      <div class="chart-grid">
        <div class="chart">Revenue</div>
        <div class="chart">Users</div>
      </div>
    </div>
  </body>
</html>
<!-- STITCH_HTML_END -->`;

    const htmlMatch = output.match(HTML_REGEX);
    assert.ok(htmlMatch, 'HTML markers should match');
    assert.match(htmlMatch[1], /<html>/);
    assert.match(htmlMatch[1], /Analytics Dashboard/);
    assert.match(htmlMatch[1], /<\/html>/);
  });

  it('handles missing screenshot marker gracefully', () => {
    const output = `<!-- STITCH_HTML_START -->
<html><body>content</body></html>
<!-- STITCH_HTML_END -->`;

    const screenshotMatch = output.match(SCREENSHOT_REGEX);
    assert.equal(screenshotMatch, null, 'Should return null when no screenshot marker');

    const htmlMatch = output.match(HTML_REGEX);
    assert.ok(htmlMatch, 'HTML should still be extractable');
  });

  it('handles missing HTML markers gracefully', () => {
    const output = `The design was generated but I could not extract the HTML.

<!-- STITCH_SCREENSHOT: https://example.com/img.png -->`;

    const htmlMatch = output.match(HTML_REGEX);
    assert.equal(htmlMatch, null, 'Should return null when no HTML markers');
  });

  it('handles multiline HTML with special characters', () => {
    const output = `<!-- STITCH_HTML_START -->
<html>
  <body>
    <p class="text-lg font-bold">Price: $99.99 &amp; free shipping</p>
    <button onclick="alert('hello')">Buy Now</button>
    <style>.container { max-width: 1200px; }</style>
  </body>
</html>
<!-- STITCH_HTML_END -->`;

    const htmlMatch = output.match(HTML_REGEX);
    assert.ok(htmlMatch, 'Should handle special characters in HTML');
    assert.match(htmlMatch[1], /\$99\.99/);
    assert.match(htmlMatch[1], /&amp;/);
  });
});
