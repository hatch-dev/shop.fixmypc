/*
 * Builds storage/resources/cities/<CC>.json - the town lists the address
 * forms offer once a county or state is picked.
 *
 * Source is the `country-state-city` package, which is NOT a dependency of
 * this project: the data is baked out here and committed, so nothing at
 * runtime carries 17MB of cities around. Regenerate with:
 *
 *   npm i --no-save country-state-city && node scripts/generate-cities.js
 *
 * Each file is keyed by the state codes in storage/resources/countries.json,
 * not the package's own - those disagree (for Ireland the package uses CO for
 * Cork where this repo uses C), and the form posts the repo's code. States
 * are matched across by name, and anything that does not match is left out,
 * which drops the town field back to free text for that state rather than
 * offering a list filed under the wrong code.
 *
 * Ireland is skipped on purpose. The package files all 372 Irish towns under
 * the four provinces rather than the 26 counties, so it cannot answer "towns
 * in Co. Meath" at all. Ireland's list lives in frontend-panel/mixin/
 * addressRules.js instead, county by county, and carries the Eircode routing
 * key each town is served by.
 */
const fs = require('fs')
const path = require('path')
const { State, City } = require('country-state-city')

const ROOT = path.resolve(__dirname, '..')
const OUT = path.join(ROOT, 'storage/resources/cities')

/* Ireland is county-accurate in the app already; see the note above. */
const SKIP = ['IE']

const normalise = name => (name || '')
  .toLowerCase()
  .normalize('NFD')
  .replace(/[̀-ͯ]/g, '')
  .replace(/^(county|province of|state of|the)\s+/, '')
  .replace(/[^a-z0-9]/g, '')

const countries = JSON.parse(
  fs.readFileSync(path.join(ROOT, 'storage/resources/countries.json'), 'utf8')
)

fs.rmSync(OUT, { recursive: true, force: true })
fs.mkdirSync(OUT, { recursive: true })

let files = 0
let towns = 0
const index = {}

for (const [code, country] of Object.entries(countries)) {
  if (SKIP.includes(code)) {
    continue
  }

  const repoStates = country.states || {}

  if (!Object.keys(repoStates).length) {
    continue
  }

  const byName = {}
  for (const [stateCode, state] of Object.entries(repoStates)) {
    byName[normalise(state.name)] = stateCode
  }

  const out = {}

  for (const state of State.getStatesOfCountry(code) || []) {
    const cities = City.getCitiesOfState(code, state.isoCode) || []

    if (!cities.length) {
      continue
    }

    // by name first; the package's own code only when this repo shares it
    const target = byName[normalise(state.name)] !== undefined
      ? byName[normalise(state.name)]
      : (repoStates[state.isoCode] ? state.isoCode : null)

    if (!target) {
      continue
    }

    const names = [...new Set(cities.map(city => city.name.trim()).filter(Boolean))]
      .sort((a, b) => a.localeCompare(b))

    out[target] = (out[target] || []).concat(names)
  }

  // a state can be fed by more than one of the package's entries
  for (const stateCode of Object.keys(out)) {
    out[stateCode] = [...new Set(out[stateCode])].sort((a, b) => a.localeCompare(b))
    towns += out[stateCode].length
  }

  if (!Object.keys(out).length) {
    continue
  }

  fs.writeFileSync(path.join(OUT, `${code}.json`), JSON.stringify(out))
  index[code] = Object.keys(out).length
  files++
}

fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify(index))

console.log(`wrote ${files} country files, ${towns} towns`)
