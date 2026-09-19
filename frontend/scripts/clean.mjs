// The build lands in ../public next to api/ and data/, so Vite must not empty
// the directory. Remove only what the previous build wrote.
import { rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const publicDir = fileURLToPath(new URL('../../public/', import.meta.url))
rmSync(publicDir + 'assets', { recursive: true, force: true })
rmSync(publicDir + 'index.html', { force: true })
