import { randomUUID } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';

export function createSessionRegistry(dataPath, defaultCallbackUrl = null) {
  const registryFile = path.join(dataPath, 'registry.json');

  async function ensureDirs() {
    await fs.mkdir(dataPath, { recursive: true });
  }

  async function read() {
    try {
      const registry = JSON.parse(await fs.readFile(registryFile, 'utf8'));

      return registry && typeof registry === 'object' && !Array.isArray(registry) ? registry : {};
    } catch {
      return {};
    }
  }

  async function write(registry) {
    await ensureDirs();
    const temporaryFile = path.join(dataPath, `.registry.${process.pid}.${randomUUID()}.tmp`);

    try {
      await fs.writeFile(temporaryFile, JSON.stringify(registry, null, 2));
      await fs.rename(temporaryFile, registryFile);
    } catch (error) {
      await fs.rm(temporaryFile, { force: true }).catch(() => {});
      throw error;
    }
  }

  async function remember(sessionId, callbackUrl) {
    const registry = await read();
    registry[sessionId] = {
      callbackUrl: callbackUrl || defaultCallbackUrl,
      updatedAt: new Date().toISOString(),
    };
    await write(registry);

    return registry[sessionId];
  }

  async function forget(sessionId) {
    const registry = await read();
    delete registry[sessionId];
    await write(registry);
  }

  function sessionDataPath(sessionId) {
    return path.join(dataPath, `session-${sessionId}`);
  }

  async function removeSessionData(sessionId) {
    await fs.rm(sessionDataPath(sessionId), { recursive: true, force: true });
  }

  return {
    ensureDirs,
    forget,
    read,
    registryFile,
    remember,
    removeSessionData,
    sessionDataPath,
    write,
  };
}
