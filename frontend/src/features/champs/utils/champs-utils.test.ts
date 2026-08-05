import { describe, expect, it } from 'vitest'
import { buildLeadsExportCsv, parseCsv } from './champs-csv'
import { filterLeads } from './champs-filters'
import { enrichLead, mergeLeads, type RawLead } from './champs-leads'
import { normalizeState, normalizeUsername, parseBoolean } from './champs-normalizers'
import { calculateScore, classifyScore } from './champs-score'
import { readStoredLeads, writeStoredLeads } from './champs-storage'

const emptyScoreInput: RawLead = {
  instagramUsername: 'empresa-zero',
  displayName: '',
  city: '',
  state: 'MG',
  followersCount: 0,
  website: '',
  phone: '',
  email: '',
  recentPostsCount: 0,
  isBusinessProfile: false,
}

describe('normalizacao Champs', () => {
  it('normaliza username sem arroba e em minusculas', () => {
    expect(normalizeUsername(' @EmpresaFicticia/ ')).toBe('empresaficticia')
  })

  it('extrai username vindo de URL do Instagram', () => {
    expect(normalizeUsername('https://www.instagram.com/PerfilFicticio/?hl=pt-br')).toBe('perfilficticio')
  })

  it('normaliza Sao Paulo para SP', () => {
    expect(normalizeState('São Paulo')).toBe('SP')
  })

  it('normaliza Rio de Janeiro para RJ', () => {
    expect(normalizeState('Rio de Janeiro')).toBe('RJ')
  })

  it('interpreta booleanos aceitos', () => {
    expect(parseBoolean('true')).toBe(true)
    expect(parseBoolean('sim')).toBe(true)
    expect(parseBoolean('yes')).toBe(true)
    expect(parseBoolean('1')).toBe(true)
    expect(parseBoolean('false')).toBe(false)
    expect(parseBoolean('não')).toBe(false)
    expect(parseBoolean('no')).toBe(false)
    expect(parseBoolean('0')).toBe(false)
  })
})

describe('importacao CSV Champs', () => {
  it('parseia CSV com virgula', () => {
    const parsed = parseCsv(
      'instagram_username,display_name,city,state,followers_count,website,phone,email,recent_posts_count,is_business_profile\n' +
        '@EmpresaAlpha,Empresa Alpha,São Paulo,SP,"5,000",https://alpha.example,11999990000,contato@alpha.example,6,sim',
    )

    expect(parsed.rejected).toBe(0)
    expect(parsed.leads).toHaveLength(1)
    expect(parsed.leads[0]).toMatchObject({
      instagramUsername: 'empresaalpha',
      followersCount: 5000,
      state: 'SP',
    })
  })

  it('parseia CSV com ponto e virgula', () => {
    const parsed = parseCsv(
      'perfil;empresa;cidade;estado;seguidores;site;telefone;email;posts_recentes;perfil_comercial\n' +
        '@EmpresaBeta;Empresa Beta;Rio de Janeiro;RJ;7.500;beta.example;21999990000;contato@beta.example;8;yes',
    )

    expect(parsed.rejected).toBe(0)
    expect(parsed.leads[0]).toMatchObject({
      instagramUsername: 'empresabeta',
      followersCount: 7500,
      state: 'RJ',
    })
  })

  it('remove BOM UTF-8 do cabecalho', () => {
    const parsed = parseCsv('\uFEFFusername,name,city,state\n@EmpresaBom,Empresa Bom,São Paulo,SP')

    expect(parsed.leads[0].instagramUsername).toBe('empresabom')
  })

  it('preserva campos com aspas e aspas escapadas', () => {
    const parsed = parseCsv(
      'instagram_username;display_name;city;state\n' +
        '"@EmpresaAspas";"Empresa ""Aspas""";"São Paulo";"SP"',
    )

    expect(parsed.leads[0].displayName).toBe('Empresa "Aspas"')
  })

  it('rejeita linha sem username', () => {
    const parsed = parseCsv('instagram_username,display_name\n,Empresa Sem Perfil')

    expect(parsed.leads).toHaveLength(0)
    expect(parsed.rejected).toBe(1)
  })

  it('aceita CSV sem cabecalho usando ordem padrao', () => {
    const parsed = parseCsv('@EmpresaSemCabecalho,Empresa Sem Cabecalho,São Paulo,SP,5200,,,,6,true')

    expect(parsed.rejected).toBe(0)
    expect(parsed.leads[0]).toMatchObject({
      instagramUsername: 'empresasemcabecalho',
      score: 65,
    })
  })
})

describe('score Champs', () => {
  it('calcula score zero', () => {
    const result = calculateScore(emptyScoreInput)

    expect(result.score).toBe(0)
    expect(result.classification).toBe('Baixo potencial')
    expect(result.reasons).toEqual([])
    expect(result.appliedCriteria).toEqual([])
  })

  it('calcula score maximo', () => {
    const result = calculateScore({
      displayName: 'Empresa Maxima',
      city: 'São Paulo',
      state: 'SP',
      followersCount: 5000,
      website: 'https://maxima.example',
      phone: '11999990000',
      email: 'contato@maxima.example',
      recentPostsCount: 6,
      isBusinessProfile: true,
    })

    expect(result.score).toBe(100)
    expect(result.classification).toBe('Alta prioridade')
    expect(result.appliedCriteria).toHaveLength(8)
  })

  it('classifica fronteiras 39 e 40', () => {
    expect(classifyScore(39)).toBe('Baixo potencial')
    expect(classifyScore(40)).toBe('Potencial médio')
  })

  it('classifica fronteiras 69 e 70', () => {
    expect(classifyScore(69)).toBe('Potencial médio')
    expect(classifyScore(70)).toBe('Bom potencial')
  })

  it('classifica fronteiras 84 e 85', () => {
    expect(classifyScore(84)).toBe('Bom potencial')
    expect(classifyScore(85)).toBe('Alta prioridade')
  })
})

describe('merge Champs', () => {
  it('nao duplica username importado novamente', () => {
    const current = [lead({ instagramUsername: '@EmpresaDuplicada', displayName: 'Empresa Duplicada' })]
    const incoming = [lead({ instagramUsername: 'empresaduplicada', city: 'São Paulo', state: 'SP' })]

    const merged = mergeLeads(current, incoming)

    expect(merged).toHaveLength(1)
    expect(merged[0]).toMatchObject({
      instagramUsername: 'empresaduplicada',
      displayName: 'Empresa Duplicada',
      city: 'São Paulo',
      state: 'SP',
    })
  })

  it('mantem o registro mais completo e atualiza campos vazios', () => {
    const current = [
      lead({
        instagramUsername: '@EmpresaCompleta',
        displayName: 'Empresa Completa',
        followersCount: 1500,
        recentPostsCount: 2,
      }),
    ]
    const incoming = [
      lead({
        instagramUsername: 'empresacompleta',
        city: 'Rio de Janeiro',
        state: 'RJ',
        followersCount: 9000,
        website: 'https://completa.example',
        email: 'contato@completa.example',
        recentPostsCount: 9,
        isBusinessProfile: true,
      }),
    ]

    const [merged] = mergeLeads(current, incoming)

    expect(merged.displayName).toBe('Empresa Completa')
    expect(merged.followersCount).toBe(9000)
    expect(merged.recentPostsCount).toBe(9)
    expect(merged.website).toBe('https://completa.example')
    expect(merged.email).toBe('contato@completa.example')
    expect(merged.score).toBeGreaterThan(current[0].score)
  })
})

describe('filtros Champs', () => {
  const leads = [
    lead({
      instagramUsername: 'empresa-sp',
      displayName: 'Empresa Paulista',
      city: 'São Paulo',
      state: 'SP',
      followersCount: 8000,
      website: 'https://sp.example',
      recentPostsCount: 7,
    }),
    lead({
      instagramUsername: 'empresa-rj',
      displayName: 'Empresa Carioca',
      city: 'Rio de Janeiro',
      state: 'RJ',
      followersCount: 6000,
      recentPostsCount: 6,
    }),
    lead({
      instagramUsername: 'empresa-mg',
      displayName: 'Empresa Mineira',
      city: 'Belo Horizonte',
      state: 'MG',
      followersCount: 500,
    }),
  ]

  it('filtra SP', () => {
    expect(filterLeads(leads, { stateFilter: 'SP', minimumScore: 0, query: '' })).toHaveLength(1)
  })

  it('filtra RJ', () => {
    expect(filterLeads(leads, { stateFilter: 'RJ', minimumScore: 0, query: '' })[0].state).toBe('RJ')
  })

  it('filtra outros estados', () => {
    expect(filterLeads(leads, { stateFilter: 'OUTROS', minimumScore: 0, query: '' })[0].state).toBe('MG')
  })

  it('filtra por score minimo', () => {
    const result = filterLeads(leads, { stateFilter: 'TODOS', minimumScore: 70, query: '' })

    expect(result.every((item) => item.score >= 70)).toBe(true)
  })

  it('busca por texto em campos relevantes', () => {
    const result = filterLeads(leads, { stateFilter: 'TODOS', minimumScore: 0, query: 'mineira' })

    expect(result).toHaveLength(1)
    expect(result[0].instagramUsername).toBe('empresa-mg')
  })
})

describe('exportacao Champs', () => {
  it('exporta apenas os leads do filtro atual', () => {
    const leads = [
      lead({
        instagramUsername: 'empresa-exportada',
        displayName: 'Empresa; Exportada',
        city: 'São Paulo',
        state: 'SP',
        followersCount: 5000,
        recentPostsCount: 6,
      }),
      lead({
        instagramUsername: 'empresa-fora',
        displayName: 'Empresa Fora',
        city: 'Curitiba',
        state: 'PR',
      }),
    ]
    const filtered = filterLeads(leads, { stateFilter: 'SP', minimumScore: 0, query: '' })
    const csv = buildLeadsExportCsv(filtered)

    expect(csv.startsWith('\uFEFF')).toBe(true)
    expect(csv).toContain('instagram_username;display_name')
    expect(csv).toContain('"Empresa; Exportada"')
    expect(csv).toContain('empresa-exportada')
    expect(csv).not.toContain('empresa-fora')
  })
})

describe('storage Champs', () => {
  it('ignora JSON invalido sem quebrar', () => {
    const storage = {
      getItem: () => '{',
      setItem: () => undefined,
    }

    expect(readStoredLeads(storage)).toEqual([])
  })

  it('retorna falso quando storage nao esta disponivel para gravacao', () => {
    expect(writeStoredLeads([], null)).toBe(false)
  })
})

function lead(overrides: Partial<RawLead> = {}) {
  return enrichLead({
    instagramUsername: overrides.instagramUsername ?? 'empresa-ficticia',
    displayName: overrides.displayName ?? '',
    city: overrides.city ?? '',
    state: overrides.state ?? '',
    followersCount: overrides.followersCount ?? 0,
    website: overrides.website ?? '',
    phone: overrides.phone ?? '',
    email: overrides.email ?? '',
    recentPostsCount: overrides.recentPostsCount ?? 0,
    isBusinessProfile: overrides.isBusinessProfile ?? false,
  })
}
