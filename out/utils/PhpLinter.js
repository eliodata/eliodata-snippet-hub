"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.lintPhpCode = void 0;
const child_process_1 = require("child_process");
/**
 * Check PHP syntax locally with `php -l` before code is sent to WordPress.
 * The code is passed on stdin and never executed. When no PHP binary is
 * installed, the check is skipped: the WordPress plugin validates the syntax too.
 */
async function lintPhpCode(code, timeoutMs = 10000) {
    // Same normalization as the plugin: the code is stored without its opening tag
    const prefix = code.match(/^\uFEFF?\s*<\?(?:php)?\s*/i)?.[0] ?? '';
    const body = code.slice(prefix.length).replace(/\?>\s*$/, '');
    const source = `<?php\n${body}\n`;
    // Lines removed with the opening tag, to report lines as written by the user
    const lineOffset = (prefix.match(/\n/g) || []).length;
    return new Promise(resolve => {
        let output = '';
        let settled = false;
        const finish = (result) => {
            if (!settled) {
                settled = true;
                resolve(result);
            }
        };
        let child;
        try {
            child = (0, child_process_1.spawn)('php', ['-n', '-l', '-d', 'display_errors=stderr'], { stdio: ['pipe', 'pipe', 'pipe'] });
        }
        catch {
            finish({ status: 'unavailable' });
            return;
        }
        const timer = setTimeout(() => {
            child.kill();
            finish({ status: 'unavailable' });
        }, timeoutMs);
        child.stdout.on('data', chunk => { output += chunk.toString(); });
        child.stderr.on('data', chunk => { output += chunk.toString(); });
        child.on('error', () => {
            clearTimeout(timer);
            finish({ status: 'unavailable' });
        });
        child.on('close', exitCode => {
            clearTimeout(timer);
            if (exitCode === 0) {
                finish({ status: 'ok' });
                return;
            }
            const match = output.match(/(?:PHP )?(?:Parse|Fatal) error:\s*(.+?) in (?:Standard input code|-) on line (\d+)/i);
            if (!match) {
                finish({ status: 'unavailable' });
                return;
            }
            // Line 1 is the opening tag added above
            const line = Math.max(1, parseInt(match[2], 10) - 1 + lineOffset);
            finish({ status: 'error', message: match[1].trim(), line });
        });
        child.stdin.on('error', () => undefined);
        child.stdin.end(source);
    });
}
exports.lintPhpCode = lintPhpCode;
//# sourceMappingURL=PhpLinter.js.map