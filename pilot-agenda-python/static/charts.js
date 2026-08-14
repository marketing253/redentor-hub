/* charts.js — gráficos SVG reutilizáveis, no mesmo estilo que os
   painéis originais (acidentes, aderência, combustível...) usavam:
   feitos à mão em SVG, sem biblioteca externa. Uma função por tipo
   de gráfico, pra todo painel novo do Grupo 3 usar igual. */

/** Barra horizontal, tipo ranking. dados = [{rotulo, valor}], já ordenado. */
function graficoBarras(dados, opcoes){
  opcoes = opcoes || {};
  const cor = opcoes.cor || 'var(--azul-claro)';
  const max = Math.max(...dados.map(d => d.valor), 1);
  return dados.map(d => `
    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px">
      <span style="width:${opcoes.larguraRotulo||110}px;flex-shrink:0;color:var(--txt2);
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(d.rotulo)}">${escHtml(d.rotulo)}</span>
      <div style="flex:1;height:16px;background:var(--sup2);border-radius:4px;overflow:hidden">
        <div style="height:100%;width:${d.valor/max*100}%;background:${cor};border-radius:4px"></div>
      </div>
      <span style="width:40px;text-align:right;color:var(--ouro-cl);font-weight:600">${d.valor}</span>
    </div>`).join('');
}

/** Rosca (donut) simples de 2 fatias: valor vs restante, com número no centro. */
function graficoRosca(valor, total, opcoes){
  opcoes = opcoes || {};
  const cor = opcoes.cor || 'var(--neg)';
  const corFundo = opcoes.corFundo || 'var(--sup2)';
  const pct = total ? valor/total : 0;
  const r = 70, cx = 90, cy = 90, circ = 2*Math.PI*r;
  const off = circ * (1 - pct);
  const rotulo = opcoes.rotulo || '';
  return `<svg viewBox="0 0 180 180" style="max-width:180px;display:block;margin:0 auto">
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${corFundo}" stroke-width="20"/>
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cor}" stroke-width="20"
      stroke-dasharray="${circ}" stroke-dashoffset="${off}" stroke-linecap="round"
      transform="rotate(-90 ${cx} ${cy})"/>
    <text x="${cx}" y="${cy-2}" text-anchor="middle" font-size="28" font-weight="700" fill="${cor}">${Math.round(pct*100)}%</text>
    <text x="${cx}" y="${cy+18}" text-anchor="middle" font-size="11" fill="var(--txt2)">${escHtml(rotulo)}</text>
  </svg>`;
}

/** Linha do tempo simples (série mensal), como uma sparkline maior. */
function graficoLinha(pontos, opcoes){
  opcoes = opcoes || {};
  const w = opcoes.largura || 600, h = opcoes.altura || 120, pad = 24;
  const max = Math.max(...pontos.map(p => p.valor), 1);
  const passo = pontos.length > 1 ? (w - pad*2) / (pontos.length - 1) : 0;
  const coords = pontos.map((p,i) => {
    const x = pad + i*passo;
    const y = h - pad - (p.valor/max)*(h - pad*2);
    return `${x},${y}`;
  });
  const cor = opcoes.cor || 'var(--ouro-cl)';
  return `<svg viewBox="0 0 ${w} ${h}" style="width:100%;height:auto">
    <polyline points="${coords.join(' ')}" fill="none" stroke="${cor}" stroke-width="2.5"/>
    ${coords.map((c,i) => {
      const [x,y] = c.split(',');
      return `<circle cx="${x}" cy="${y}" r="3" fill="${cor}"><title>${escHtml(pontos[i].rotulo)}: ${pontos[i].valor}</title></circle>`;
    }).join('')}
  </svg>`;
}

function escHtml(s){ const d = document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
