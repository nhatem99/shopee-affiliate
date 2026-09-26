import { compile } from 'tailwindcss'
const css = `@import 'tailwindcss';
@theme { --shadow-card: 0 1px 2px rgba(0,0,0,.04), 0 4px 24px rgba(0,0,0,.07); --color-mine:#123456; --text-money: 1.375rem; --text-money--line-height: 1.6rem; --text-xs--line-height: 1.15rem; }
.dark { --shadow-card: 0 4px 28px rgba(0,0,0,.45); }
`
const c = await compile(css, { base: process.cwd(), loadStylesheet: async (id, base) => {
  const fs = await import('node:fs/promises'); const path = await import('node:path')
  const p = path.resolve(process.cwd(), 'node_modules/tailwindcss/index.css')
  return { path: p, base: path.dirname(p), content: await fs.readFile(p,'utf8') }
}})
const out = c.build(['shadow-card','bg-mine','text-money','text-xs','shadow-card/50'])
for (const line of out.split('\n')) {
  if (/shadow-card|bg-mine|text-money|\.text-xs/.test(line) || /--tw-shadow/.test(line)) console.log(line)
}
