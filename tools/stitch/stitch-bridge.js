#!/usr/bin/env node

/**
 * Stitch Bridge — CLI wrapper around @google/stitch-sdk
 *
 * Called by Dispatch agents via the Bash tool to generate and edit
 * UI screens using Google Stitch. All output is JSON to stdout.
 * Errors go to stderr with exit code 1.
 *
 * Usage:
 *   node stitch-bridge.js generate --project <id> --prompt <text> [--vibe <text>] [--device <type>]
 *   node stitch-bridge.js edit --screen <id> --prompt <text>
 *   node stitch-bridge.js list-projects
 *   node stitch-bridge.js create-project --title <text>
 *   node stitch-bridge.js list-projects
 *
 * Environment:
 *   STITCH_API_KEY — required, read from process.env (never passed via CLI args)
 */

import { StitchToolClient } from '@google/stitch-sdk';

const VALID_DEVICES = ['MOBILE', 'DESKTOP', 'TABLET', 'AGNOSTIC'];

function usage() {
  console.error(`Usage:
  stitch-bridge.js generate --project <id> --prompt <text> [--vibe <text>] [--device <type>]
  stitch-bridge.js edit --screen <id> --prompt <text>
  stitch-bridge.js list-projects
  stitch-bridge.js create-project --title <text>
  stitch-bridge.js list-projects

Device types: ${VALID_DEVICES.join(', ')}
Requires STITCH_API_KEY environment variable.`);
  process.exit(1);
}

function parseArgs(argv) {
  const args = {};
  const command = argv[2];
  if (!command || command.startsWith('-')) {
    return { command: null, args };
  }

  for (let i = 3; i < argv.length; i++) {
    const arg = argv[i];
    if (arg.startsWith('--') && i + 1 < argv.length) {
      const key = arg.slice(2);
      args[key] = argv[++i];
    }
  }

  return { command, args };
}

/**
 * Read JSON from stdin if available. Merges with CLI args (stdin wins).
 * This avoids OS argument length limits for long values like prompts and vibe.
 */
async function readStdin() {
  if (process.stdin.isTTY) return {};

  return new Promise((resolve) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => { data += chunk; });
    process.stdin.on('end', () => {
      if (!data.trim()) return resolve({});
      try {
        resolve(JSON.parse(data));
      } catch {
        resolve({});
      }
    });
    // Timeout after 100ms if no stdin data arrives
    setTimeout(() => {
      process.stdin.destroy();
      resolve({});
    }, 100);
  });
}

async function fetchUrl(url) {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Failed to fetch ${url}: ${response.status} ${response.statusText}`);
  }
  return response.text();
}

async function generate(client, args) {
  const { project, prompt, vibe, device } = args;

  if (!project) throw new Error('Missing required --project argument');
  if (!prompt) throw new Error('Missing required --prompt argument');

  const deviceType = (device || 'DESKTOP').toUpperCase();
  if (!VALID_DEVICES.includes(deviceType)) {
    throw new Error(`Invalid device type: ${device}. Must be one of: ${VALID_DEVICES.join(', ')}`);
  }

  // Build the full prompt with optional vibe (design system context)
  const fullPrompt = vibe
    ? `Match this existing design system:\n${vibe}\n\n${prompt}`
    : prompt;

  // Generate screen via Stitch SDK tool interface
  const result = await client.callTool('generate_screen_from_text', {
    projectId: project,
    prompt: fullPrompt,
    deviceType,
  });

  // Extract screen data from result
  const screen = result?.content?.[0]?.text ? JSON.parse(result.content[0].text) : result;
  const screenId = screen.screenId || screen.screen_id || screen.id;
  const projectId = screen.projectId || screen.project_id || project;

  if (!screenId) {
    throw new Error('Stitch returned no screen ID — generation may have failed');
  }

  // Fetch HTML and screenshot URL
  const htmlResult = await client.callTool('get_html', { screenId });
  const imageResult = await client.callTool('get_image', { screenId });

  const htmlUrl = htmlResult?.content?.[0]?.text;
  const imageUrl = imageResult?.content?.[0]?.text;

  // Fetch actual HTML content
  let html = '';
  if (htmlUrl) {
    try {
      html = await fetchUrl(htmlUrl);
    } catch (e) {
      console.error(`Warning: Could not fetch HTML content: ${e.message}`);
    }
  }

  if (!html || html.trim().length === 0) {
    throw new Error('Stitch returned empty HTML — design generation may have failed');
  }

  return {
    screen_id: screenId,
    project_id: projectId,
    html,
    screenshot_url: imageUrl || null,
  };
}

async function edit(client, args) {
  const { screen, prompt } = args;

  if (!screen) throw new Error('Missing required --screen argument');
  if (!prompt) throw new Error('Missing required --prompt argument');

  const result = await client.callTool('edit_screen', {
    screenId: screen,
    prompt,
  });

  const screenData = result?.content?.[0]?.text ? JSON.parse(result.content[0].text) : result;
  const screenId = screenData.screenId || screenData.screen_id || screen;

  // Fetch updated HTML and screenshot
  const htmlResult = await client.callTool('get_html', { screenId });
  const imageResult = await client.callTool('get_image', { screenId });

  const htmlUrl = htmlResult?.content?.[0]?.text;
  const imageUrl = imageResult?.content?.[0]?.text;

  let html = '';
  if (htmlUrl) {
    try {
      html = await fetchUrl(htmlUrl);
    } catch (e) {
      console.error(`Warning: Could not fetch HTML content: ${e.message}`);
    }
  }

  return {
    screen_id: screenId,
    html,
    screenshot_url: imageUrl || null,
  };
}

async function listProjects(client) {
  const result = await client.callTool('list_projects', {});
  const projects = result?.projects || [];

  return projects.map((p) => ({
    project_id: p.projectId || p.project_id || p.id || p.name?.split('/').pop(),
    title: p.title || p.displayName || p.name || 'Untitled',
  }));
}

async function createProject(client, args) {
  const { title } = args;

  if (!title) throw new Error('Missing required --title argument');

  const result = await client.callTool('create_project', {
    title,
  });

  const data = result?.content?.[0]?.text ? JSON.parse(result.content[0].text) : result;
  const projectId = data.projectId || data.project_id || data.id;

  if (!projectId) {
    throw new Error('Stitch returned no project ID — creation may have failed');
  }

  return {
    project_id: projectId,
    title,
  };
}

async function listProjects(client) {
  const result = await client.callTool('list_projects', {});

  const data = result?.content?.[0]?.text ? JSON.parse(result.content[0].text) : result;

  // Normalize to array of { project_id, title }
  const projects = Array.isArray(data) ? data : (data.projects || []);

  return {
    projects: projects.map(p => ({
      project_id: p.projectId || p.project_id || p.id,
      title: p.title || p.name || '',
    })),
  };
}

async function main() {
  const { command, args } = parseArgs(process.argv);

  if (!command) usage();

  // Merge stdin JSON with CLI args (stdin wins for long values like prompt, vibe)
  const stdinData = await readStdin();
  Object.assign(args, stdinData);

  if (!process.env.STITCH_API_KEY) {
    console.error('Error: STITCH_API_KEY environment variable is required');
    process.exit(1);
  }

  const client = new StitchToolClient({
    apiKey: process.env.STITCH_API_KEY,
  });

  try {
    let result;

    switch (command) {
      case 'generate':
        result = await generate(client, args);
        break;
      case 'edit':
        result = await edit(client, args);
        break;
      case 'list-projects':
        result = await listProjects(client);
        break;
      case 'create-project':
        result = await createProject(client, args);
        break;
      case 'list-projects':
        result = await listProjects(client);
        break;
      default:
        console.error(`Unknown command: ${command}`);
        usage();
    }

    // JSON-only stdout output
    console.log(JSON.stringify(result, null, 2));
  } catch (error) {
    console.error(`Error: ${error.message}`);
    process.exit(1);
  } finally {
    try {
      await client.close();
    } catch {
      // Ignore close errors
    }
  }
}

main();
