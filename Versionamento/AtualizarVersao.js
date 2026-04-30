const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const versionConfigPath = path.join(repoRoot, 'Versionamento', 'Versao.json');
const packageJsonPath = path.join(repoRoot, 'package.json');
const packageLockPath = path.join(repoRoot, 'package-lock.json');
const gptActionOpenApiPath = path.join(repoRoot, 'APIChat', 'GPTActionConfigurador.openapi.json');
const ignoredDirectories = new Set(['.git', 'node_modules', 'LogsErros']);
const versionPattern = /versao\.[0-9.]+/g;

function readJson(filePath) {
    try {
        const content = fs.readFileSync(filePath, 'utf8');
        return JSON.parse(content);
    } catch (error) {
        throw new Error(`Failed to read JSON from ${filePath}: ${error.message}`);
    }
}

function resolveVersion() {
    if (fs.existsSync(versionConfigPath)) {
        const config = readJson(versionConfigPath);
        if (config && typeof config.version === 'string' && config.version.trim()) {
            return config.version.trim();
        }
    }

    const pkg = readJson(packageJsonPath);
    if (pkg && typeof pkg.version === 'string' && pkg.version.trim()) {
        const raw = pkg.version.trim();
        return raw.startsWith('versao.') ? raw : `versao.${raw}`;
    }

    throw new Error('Unable to resolve the application version.');
}

function extractNpmVersion(version) {
    return version.startsWith('versao.') ? version.slice('versao.'.length) : version;
}

function updateJsonFile(filePath, updater, changedFiles) {
    if (!fs.existsSync(filePath)) {
        return;
    }

    const originalContent = fs.readFileSync(filePath, 'utf8');
    const data = JSON.parse(originalContent);
    const changed = updater(data);

    if (!changed) {
        return;
    }

    fs.writeFileSync(filePath, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
    changedFiles.push(path.relative(repoRoot, filePath));
}

function walk(directory, visitor) {
    const entries = fs.readdirSync(directory, { withFileTypes: true });
    for (const entry of entries) {
        if (ignoredDirectories.has(entry.name)) {
            continue;
        }

        const fullPath = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            walk(fullPath, visitor);
        } else {
            visitor(fullPath);
        }
    }
}

function shouldProcess(filePath) {
    if (filePath === versionConfigPath) {
        return false;
    }

    const ext = path.extname(filePath).toLowerCase();
    return ['.html', '.js', '.php', '.json', '.css', '.txt', ''].includes(ext);
}

function updateFileVersion(filePath, version, changedFiles) {
    if (!shouldProcess(filePath)) {
        return;
    }

    const originalContent = fs.readFileSync(filePath, 'utf8');
    if (!versionPattern.test(originalContent)) {
        versionPattern.lastIndex = 0;
        return;
    }
    versionPattern.lastIndex = 0;

    const updatedContent = originalContent.replace(versionPattern, version);
    if (updatedContent !== originalContent) {
        fs.writeFileSync(filePath, updatedContent, 'utf8');
        changedFiles.push(path.relative(repoRoot, filePath));
    }
}

function run() {
    const version = resolveVersion();
    const npmVersion = extractNpmVersion(version);
    const changedFiles = [];

    walk(repoRoot, (filePath) => updateFileVersion(filePath, version, changedFiles));

    updateJsonFile(packageJsonPath, (data) => {
        if (data.version === npmVersion) {
            return false;
        }
        data.version = npmVersion;
        return true;
    }, changedFiles);

    updateJsonFile(packageLockPath, (data) => {
        let updated = false;

        if (data.version !== npmVersion) {
            data.version = npmVersion;
            updated = true;
        }

        if (data.packages && data.packages[''] && data.packages[''].version !== npmVersion) {
            data.packages[''].version = npmVersion;
            updated = true;
        }

        return updated;
    }, changedFiles);

    updateJsonFile(gptActionOpenApiPath, (data) => {
        if (!data.info || typeof data.info !== 'object') {
            return false;
        }

        if (data.info.version === npmVersion) {
            return false;
        }

        data.info.version = npmVersion;
        return true;
    }, changedFiles);

    if (changedFiles.length > 0) {
        console.log(`Updated version references to ${version} in:`);
        changedFiles.forEach(file => console.log(` - ${file}`));
    } else {
        console.log(`No version references required updates. Current version: ${version}`);
    }
}

try {
    run();
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
