(function (window) {
    const HELP_CATALOG_VERSION = '2026-03-23-5';
    const HELP_IMAGE_PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAAAAACwAAAAAAQABAAA=';

    const sharedGuidedConfigUtils = {
        valorSelecionado(id) {
            const elemento = document.getElementById(id);
            return elemento?.value?.trim() ||
                elemento?.selectedOptions?.[0]?.value?.trim() ||
                elemento?.selectedOptions?.[0]?.textContent?.trim() ||
                '';
        },

        normalizarOpcao(valor) {
            return (valor || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toUpperCase();
        },

        valoresSelecionados(id) {
            const elemento = document.getElementById(id);
            const valorBruto = sharedGuidedConfigUtils.valorSelecionado(id);
            const valorNormalizado = sharedGuidedConfigUtils.normalizarOpcao(valorBruto);

            if (!valorBruto || valorNormalizado === 'N' || valorNormalizado === 'NAO') {
                return [];
            }

            if (elemento?.tagName === 'SELECT') {
                const valores = elemento.multiple
                    ? Array.from(elemento.selectedOptions || []).map((option) => option.value?.trim()).filter(Boolean)
                    : [valorBruto.trim()];

                return valores.filter((valor, indice, lista) => indice === lista.findIndex((item) =>
                    sharedGuidedConfigUtils.normalizarOpcao(item) === sharedGuidedConfigUtils.normalizarOpcao(valor)
                ));
            }

            return valorBruto
                .split(/[|;,]+/)
                .map((valor) => valor.trim())
                .filter(Boolean)
                .filter((valor, indice, lista) => indice === lista.findIndex((item) =>
                    sharedGuidedConfigUtils.normalizarOpcao(item) === sharedGuidedConfigUtils.normalizarOpcao(valor)
                ));
        },

        opcaoFormatada(titulo, descricao, emDestaque = false) {
            const conteudo = `${emDestaque ? '<i>' : ''}<strong>${titulo}:</strong> ${descricao}${emDestaque ? '</i>' : ''}`;
            return `<li>${conteudo}</li>`;
        },

        escaparAtributoHtml(valor) {
            return String(valor || '')
                .replace(/&/g, '&amp;')
                .replace(/'/g, '&#39;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },

        normalizarCaminhoImagem(src = '') {
            const caminho = String(src || '').trim();
            if (!caminho) {
                return '';
            }

            const caminhoNormalizado = caminho.replace(/\.webp$/i, '.webp');
            if (!/^(?:[a-z]+:|data:)/i.test(caminhoNormalizado) && /\/ImagensConfiguracaoGuiada\//i.test(caminhoNormalizado)) {
                const separador = caminhoNormalizado.includes('?') ? '&' : '?';
                return `${caminhoNormalizado}${separador}v=${encodeURIComponent(HELP_CATALOG_VERSION)}`;
            }

            return caminhoNormalizado;
        },

        montarCandidatosImagem(...candidatos) {
            return candidatos
                .flatMap((candidato) => Array.isArray(candidato) ? candidato : [candidato])
                .map((candidato) => sharedGuidedConfigUtils.normalizarCaminhoImagem(candidato))
                .filter(Boolean)
                .filter((candidato, indice, lista) => lista.indexOf(candidato) === indice);
        },

        imagemFormatada(src, alt, fallbackSrc = '') {
            const candidatos = sharedGuidedConfigUtils.montarCandidatosImagem(src, fallbackSrc);
            if (!candidatos.length) {
                return '';
            }

            const candidatosSerializados = sharedGuidedConfigUtils.escaparAtributoHtml(candidatos.join('|'));
            const altEscapado = sharedGuidedConfigUtils.escaparAtributoHtml(alt);
            const srcInicial = candidatos.length > 1
                ? HELP_IMAGE_PLACEHOLDER
                : (candidatos[0] || HELP_IMAGE_PLACEHOLDER);
            const srcInicialEscapado = sharedGuidedConfigUtils.escaparAtributoHtml(srcInicial);

            return `<img src='${srcInicialEscapado}' data-help-image-sources='${candidatosSerializados}' alt='${altEscapado}' loading='lazy' decoding='async' style='display:block; max-width:100%; margin-top:12px;'>`;
        },

        obterImagemPadraoCampo(idCampo, alt = '') {
            return {
                src: sharedGuidedConfigUtils.normalizarCaminhoImagem(`/ImagensConfiguracaoGuiada/${idCampo}.webp`),
                alt: alt || idCampo,
            };
        },

        obterImagensOpcao({ idCampo, valorOpcao, imagemManual, altPadrao = '' }) {
            const imagemPadraoCampo = sharedGuidedConfigUtils.obterImagemPadraoCampo(idCampo, altPadrao || idCampo);

            if (imagemManual?.src) {
                const fallbackManual = imagemManual.fallbackSrc === null
                    ? ''
                    : (imagemManual.fallbackSrc || imagemPadraoCampo.src);

                return [{
                    ...imagemManual,
                    src: sharedGuidedConfigUtils.normalizarCaminhoImagem(imagemManual.src),
                    fallbackSrc: sharedGuidedConfigUtils.normalizarCaminhoImagem(fallbackManual),
                    alt: imagemManual.alt || altPadrao || imagemPadraoCampo.alt,
                }];
            }

            if (!valorOpcao) {
                return [imagemPadraoCampo];
            }

            return [
                imagemPadraoCampo,
                {
                    src: sharedGuidedConfigUtils.normalizarCaminhoImagem(`/ImagensConfiguracaoGuiada/${valorOpcao}.webp`),
                    alt: altPadrao || valorOpcao,
                },
            ].filter((imagem, indice, lista) => imagem?.src &&
                indice === lista.findIndex((item) => item?.src === imagem.src));
        },

        obterOpcoesDisponiveis(idCampo) {
            const tokensDisponiveis = new Set();
            const select = document.getElementById(idCampo);

            if (select?.tagName === 'SELECT') {
                Array.from(select.options).forEach((option) => {
                    if (option.disabled || !option.value?.trim()) {
                        return;
                    }

                    tokensDisponiveis.add(sharedGuidedConfigUtils.normalizarOpcao(option.value));
                    tokensDisponiveis.add(sharedGuidedConfigUtils.normalizarOpcao(option.textContent));
                });

                return tokensDisponiveis;
            }

            const checklist = document.querySelectorAll(`#checklist${idCampo} input[type="checkbox"]`);
            if (!checklist.length) {
                return tokensDisponiveis;
            }

            checklist.forEach((checkbox) => {
                const label = checkbox.closest('label');
                if (checkbox.disabled || label?.style?.display === 'none') {
                    return;
                }

                tokensDisponiveis.add(sharedGuidedConfigUtils.normalizarOpcao(checkbox.value));
                tokensDisponiveis.add(sharedGuidedConfigUtils.normalizarOpcao(label?.textContent));
            });

            return tokensDisponiveis;
        },

        opcaoEstaDisponivel(opcao, tokensDisponiveis) {
            if (!tokensDisponiveis?.size) {
                return true;
            }

            const candidatos = [
                opcao.valor,
                opcao.titulo,
                ...(Array.isArray(opcao.sinonimos) ? opcao.sinonimos : []),
            ]
                .filter(Boolean)
                .map((valor) => sharedGuidedConfigUtils.normalizarOpcao(valor));

            return candidatos.some((valor) => tokensDisponiveis.has(valor));
        },

        montarBlocoOpcoes({ idCampo, opcoes = [], imagensPorOpcao = {}, filtrarPorDisponibilidade = false, imagemPadrao = null }) {
            const opcoesSelecionadas = sharedGuidedConfigUtils.valoresSelecionados(idCampo)
                .map((valor) => sharedGuidedConfigUtils.normalizarOpcao(valor));
            const destaques = new Set(opcoesSelecionadas);
            const tokensDisponiveis = filtrarPorDisponibilidade
                ? sharedGuidedConfigUtils.obterOpcoesDisponiveis(idCampo)
                : null;
            const opcoesVisiveis = filtrarPorDisponibilidade
                ? opcoes.filter((opcao) => sharedGuidedConfigUtils.opcaoEstaDisponivel(opcao, tokensDisponiveis))
                : opcoes;
            const opcoesHtml = opcoesVisiveis
                .map((opcao) => sharedGuidedConfigUtils.opcaoFormatada(
                    opcao.titulo,
                    opcao.descricao,
                    destaques.has(sharedGuidedConfigUtils.normalizarOpcao(opcao.valor ?? opcao.titulo))
                ))
                .join('');

            const imagensHtml = opcoesSelecionadas
                .flatMap((opcao) => {
                    const imagens = imagensPorOpcao[opcao];
                    const imagensNormalizadas = Array.isArray(imagens)
                        ? imagens
                        : sharedGuidedConfigUtils.obterImagensOpcao({
                            idCampo,
                            valorOpcao: opcao,
                            imagemManual: imagens,
                            altPadrao: idCampo,
                        });

                    return imagensNormalizadas.map((imagem) => {
                        if (imagem?.src) {
                            return imagem;
                        }

                        return sharedGuidedConfigUtils.obterImagensOpcao({
                            idCampo,
                            valorOpcao: opcao,
                            altPadrao: idCampo,
                        })[0];
                    });
                })
                .filter((imagem) => imagem?.src)
                .map((imagem) => sharedGuidedConfigUtils.imagemFormatada(imagem.src, imagem.alt, imagem.fallbackSrc))
                .join('');

            const imagemPadraoNormalizada = imagemPadrao?.src
                ? {
                    ...imagemPadrao,
                    alt: imagemPadrao.alt || idCampo,
                }
                : sharedGuidedConfigUtils.obterImagemPadraoCampo(idCampo, idCampo);

            return {
                opcoesSelecionadas,
                destaques,
                opcoesHtml: opcoesHtml ? `<ul>${opcoesHtml}</ul>` : '',
                imagensHtml,
                imagemPadrao: imagemPadraoNormalizada,
            };
        },
    };

    const pageHelpFactories = {

        SeletoresConfiguradorAC() {
        },

        SeletoresConfiguradorAE() {
        },

        SeletoresConfiguradorFX() {
        },

        SeletoresConfiguradorHY() {
        },

        SeletoresConfiguradorIN() {
        },

        SeletoresConfiguradorMO() {
            const {
                imagemFormatada,
                montarBlocoOpcoes,
                obterImagemPadraoCampo,
            } = sharedGuidedConfigUtils;

            return {

                GrupoMOLN() {
                    const { opcoesHtml, imagensHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOLN',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            {
                                valor: '2.I',
                                titulo: 'IBR MS/ML',
                                descricao: 'Linha de motores para aplicações gerais. A linha MS corresponde aos motores trifásicos standard, enquanto a linha ML corresponde aos motores monofásicos.',
                            },
                            {
                                valor: '3.I',
                                titulo: 'IBR T3A/T3C',
                                descricao: 'Linha de motores trifásicos de alto rendimento, indicada para aplicações industriais com maior exigência de desempenho.',
                            },
                            {
                                valor: '3.W',
                                titulo: 'WEG T3A',
                                descricao: 'Opção com motorização WEG para composições específicas do conjunto motriz.',
                            },
                            {
                                valor: '3.APM',
                                titulo: 'IBR APM',
                                descricao: 'Linha anticorrosiva com carcaça de alumínio indicada para ambientes agressivos, úmidos ou sujeitos à lavagem frequente.',
                            },
                            {
                                valor: '3.SPM',
                                titulo: 'IBR SPM',
                                descricao: 'Linha anticorrosiva com carcaça de inox voltada a aplicações com exigência elevada de resistência superficial e proteção adicional.',
                            },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Linha do Motor:',
                        text: `<p><strong>Define a linha construtiva do motor.</strong> Esse campo identifica a família à qual o motor pertence, ajudando na escolha da solução mais adequada conforme a aplicação, o ambiente de trabalho e a configuração desejada.</p>` +
                            `<p><strong>O Que Observar:</strong> Cada linha pode atender necessidades diferentes, como aplicações gerais, alto rendimento, composição com motores WEG ou resistência a ambientes agressivos e corrosivos.</p>` +
                            `<p><strong>Linhas Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            (imagensHtml || imagemFormatada(imagemPadrao.src, 'Linha do Motor', imagemPadrao.fallbackSrc))
                    };
                },

                GrupoMOTP() {
                    const { opcoesHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOTP',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            {
                                valor: 'M',
                                titulo: 'Monofásico',
                                descricao: 'Indicado para instalações com alimentação monofásica, comum em aplicações leves, comerciais e em equipamentos específicos.',
                            },
                            {
                                valor: 'T',
                                titulo: 'Trifásico',
                                descricao: 'Indicado para aplicações industriais, com melhor regularidade de torque, maior eficiência e alimentação trifásica.',
                            },
                            {
                                valor: 'F',
                                titulo: 'Trifásico com Freio',
                                descricao: 'Motor com sistema de frenagem incorporado, utilizado quando é necessário parar o conjunto com mais rapidez, precisão ou segurança.',
                            },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Tipo do Motor:',
                        text: `<p><strong>Define o tipo de alimentação e a característica principal de operação do motor.</strong> Esse campo indica se o motor será monofásico, trifásico ou com freio.</p>` +
                            `<p><strong>O Que Observar:</strong> A seleção deve ser compatível com a rede elétrica disponível e com a necessidade operacional da aplicação. Em alguns casos, além da alimentação, também é necessário considerar a necessidade de frenagem do conjunto.</p>` +
                            `<p><strong>Tipos Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            imagemFormatada(imagemPadrao.src, 'Tipo do Motor', imagemPadrao.fallbackSrc)
                    };
                },

                GrupoMOTT() {
                    const { opcoesHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOTT',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            { titulo: '127/220V', descricao: 'Permite ligação em 127V ou 220V, conforme a placa de identificação e o esquema elétrico do fabricante.' },
                            { titulo: '127/240V', descricao: 'Permite ligação em 127V ou 240V, conforme a configuração elétrica do motor.' },
                            { titulo: '200/400V', descricao: 'Aplicado em redes de 200V ou 400V, conforme a ligação prevista para o motor.' },
                            { titulo: '220/380/440V', descricao: 'Permite adequação a três faixas de tensão, conforme a configuração elétrica adotada.' },
                            { titulo: '220/380V', descricao: 'Muito usado em aplicações industriais, com possibilidade de ligação em 220V ou 380V.' },
                            { titulo: '220/440V', descricao: 'Atende redes de 220V ou 440V, conforme a ligação correta do motor.' },
                            { titulo: '220V', descricao: 'Tensão única, exige compatibilidade direta com a rede disponível no local.' },
                            { titulo: '254/440V', descricao: 'Permite ligação em 254V ou 440V, conforme a configuração elétrica do motor.' },
                            { titulo: '380/660V', descricao: 'Indicado para redes industriais de maior tensão, com possibilidade de ligação em 380V ou 660V.' },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Tensão do Motor:',
                        text: `<p><strong>Define a tensão elétrica de alimentação do motor.</strong> Esse campo mostra em quais redes o motor pode operar e orienta a escolha correta conforme a instalação disponível no local.</p>` +
                            `<p><strong>O Que Observar:</strong> Tensões com dois ou mais valores indicam possibilidades de ligação diferentes para o mesmo motor. A ligação correta deve sempre seguir a placa de identificação e o esquema elétrico do fabricante.</p>` +
                            `<p><strong>Tensões Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            imagemFormatada(imagemPadrao.src, 'Tensão do Motor', imagemPadrao.fallbackSrc)
                    };
                },

                GrupoMOFQ() {
                    const { opcoesHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOFQ',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            { valor: '60', titulo: '60Hz', descricao: 'Padrão mais comum no Brasil. A frequência influencia diretamente a rotação nominal do motor.', sinonimos: ['DE60'] },
                            { valor: '50', titulo: '50Hz', descricao: 'Usado em instalações que operam em 50Hz, com rotação nominal compatível com essa frequência.', sinonimos: ['DE50'] },
                            { valor: '50/60', titulo: '50/60Hz', descricao: 'Motor apto a operar em ambas as frequências, respeitando os dados de placa e as condições da aplicação.' },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Frequência do Motor:',
                        text: `<p><strong>Define a frequência elétrica de operação do motor.</strong> Esse dado indica em qual padrão de rede o motor foi projetado para trabalhar e influencia diretamente a rotação nominal do conjunto.</p>` +
                            `<p><strong>O Que Observar:</strong> A frequência deve ser compatível com a alimentação disponível ou com a configuração de acionamento utilizada. Alterações de frequência podem modificar a rotação e o comportamento do motor na aplicação.</p>` +
                            `<p><strong>Frequências Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            imagemFormatada(imagemPadrao.src, 'Frequência do Motor', imagemPadrao.fallbackSrc)
                    };
                },

                GrupoMOPT() {
                    const imagemPadrao = obterImagemPadraoCampo('MOPT', 'Potência do Motor');
                    return {
                        title: 'Potência do Motor:',
                        text: `<p><strong>Define a capacidade de trabalho do motor.</strong> A potência indica quanto o motor pode entregar para acionar o equipamento, sendo um dos dados mais importantes para o dimensionamento correto da aplicação.</p>` +
                            `<p><strong>O Que Observar:</strong> A escolha da potência deve considerar a carga exigida pela máquina, o regime de trabalho e a necessidade de desempenho do conjunto. Um motor subdimensionado pode trabalhar sobrecarregado, enquanto um motor superdimensionado pode representar uma escolha inadequada para a aplicação.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a identificar qual motorização atende melhor o equipamento, contribuindo para funcionamento adequado, segurança operacional e melhor compatibilidade com a aplicação desejada.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Potência do Motor', imagemPadrao.fallbackSrc)

                    };
                    imagensPorOpcao: { }
                },

                GrupoMOPL() {
                    const { opcoesHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOPL',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            { valor: '2', titulo: '2 polos', descricao: 'Associada a rotações mais altas, indicada quando a aplicação exige maior velocidade.' },
                            { valor: '4', titulo: '4 polos', descricao: 'Opção bastante usada em aplicações gerais, com bom equilíbrio entre rotação e torque.' },
                            { valor: '6', titulo: '6 polos', descricao: 'Associada a rotações menores, indicada quando a aplicação pede funcionamento mais controlado.' },
                            { valor: '8', titulo: '8 polos', descricao: 'Indicada para aplicações de baixa rotação, com prioridade para controle e suavidade de operação.' },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Polaridade do Motor:',
                        text: `<p><strong>Define a quantidade de polos do motor.</strong> A polaridade está diretamente ligada à rotação nominal e influencia o comportamento do motor conforme a necessidade da aplicação.</p>` +
                            `<p><strong>O Que Observar:</strong> De modo geral, quanto menor a quantidade de polos, maior tende a ser a rotação do motor. Já motores com mais polos normalmente trabalham com rotações menores.</p>` +
                            `<p><strong>Polaridades Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            imagemFormatada(imagemPadrao.src, 'Polaridade do Motor', imagemPadrao.fallbackSrc)
                    };
                },

                GrupoMOCM() {
                    const imagemPadrao = obterImagemPadraoCampo('MOCM', 'Carcaça do Motor');
                    return {
                        title: 'Carcaça do Motor:',
                        text: `<p><strong>Define o tamanho construtivo padronizado do motor.</strong> A carcaça identifica dimensões mecânicas como altura de eixo, pontos de fixação e proporções gerais do conjunto.</p>` +
                            `<p><strong>O Que Observar:</strong> A escolha da carcaça deve considerar o espaço disponível para montagem, a forma construtiva e a compatibilidade mecânica com o equipamento acionado. Em geral, números maiores indicam motores fisicamente maiores.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a verificar se o motor poderá ser instalado corretamente no local desejado, respeitando medidas de fixação, acoplamento e espaço de montagem.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Carcaça do Motor', imagemPadrao.fallbackSrc)
                    };
                    imagensPorOpcao: { }
                },

                GrupoMOCP() {
                    const { opcoesHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOCP',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            { valor: 'B5', titulo: 'B5', descricao: 'Motor com flange tipo FF, sem pés, indicado para montagem flangeada.' },
                            { valor: 'B35', titulo: 'B35', descricao: 'Motor com flange tipo FF e pés, combinação de montagem flangeada com apoio na base.' },
                            { valor: 'B14', titulo: 'B14', descricao: 'Motor com flange tipo C-DIN, sem pés, indicado para montagens mais compactas.' },
                            { valor: 'B34', titulo: 'B34', descricao: 'Motor com flange tipo C-DIN e pés, combinação de montagem frontal com apoio na base.' },
                            { valor: 'B3', titulo: 'B3', descricao: 'Motor com tampa e pés, sem flange, indicado para montagem apoiada na base do equipamento.' },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Forma Construtiva do Motor:',
                        text: `<p><strong>Define a forma de montagem do motor.</strong> Esse campo indica como o motor será fixado na aplicação, considerando flange, pés de apoio ou a combinação entre essas opções.</p>` +
                            `<p><strong>O Que Observar:</strong> A forma construtiva deve ser escolhida conforme o tipo de acoplamento e a estrutura de montagem da máquina. Uma seleção incorreta pode impedir a instalação correta do motor no equipamento.</p>` +
                            `<p><strong>Formas Construtivas Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            imagemFormatada(imagemPadrao.src, 'Forma Construtiva do Motor', imagemPadrao.fallbackSrc)
                    };
                },

                GrupoMOCPP() {
                    const imagemPadrao = obterImagemPadraoCampo('MOCPP', 'Posição dos Pés do Motor');
                    return {
                        title: 'Posição dos Pés do Motor:',
                        text: `<p><strong>Define a orientação dos pés do motor.</strong> Esse campo indica de qual lado os pés ficam posicionados no corpo do motor, informação importante para a montagem correta do conjunto.</p>` +
                            `<p><strong>O Que Observar:</strong> A posição dos pés deve ser compatível com a forma de instalação do motor no equipamento, evitando interferências mecânicas e facilitando o encaixe correto do conjunto.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a organizar a montagem do motor no equipamento, principalmente quando há limitação de espaço, orientação específica do conjunto ou necessidade de padronização mecânica.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Posição dos Pés do Motor', imagemPadrao.fallbackSrc)
                    };
                    imagensPorOpcao: { }
                },

                GrupoMOPC() {
                    const imagemPadrao = obterImagemPadraoCampo('MOPC', 'Posição da Caixa de Ligação');
                    return {
                        title: 'Posição da Caixa de Ligação:',
                        text: `<p><strong>Define a orientação da caixa de ligação do motor.</strong> Esse campo indica em qual posição a caixa elétrica será montada, influenciando o acesso para conexão e manutenção.</p>` +
                            `<p><strong>O Que Observar:</strong> A posição da caixa de ligação deve ser escolhida de acordo com o espaço disponível, com a passagem dos cabos e com o acesso necessário para instalação e manutenção, evitando interferências com outros componentes.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a definir a melhor orientação da caixa de ligação no motor, facilitando a montagem elétrica, o acabamento da instalação e o acesso em intervenções futuras.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Posição da Caixa de Ligação', imagemPadrao.fallbackSrc)
                    };
                    imagensPorOpcao: { }
                },

                GrupoMOPM() {
                    const imagemPadrao = obterImagemPadraoCampo('MOPM', 'Posição de Montagem do Motor');
                    return {
                        title: 'Posição de Montagem do Motor:',
                        text: `<p><strong>Define a posição em que o motor será instalado.</strong> Esse campo indica a orientação de montagem do motor no equipamento, sendo importante para garantir funcionamento correto e compatibilidade mecânica.</p>` +
                            `<p><strong>O Que Observar:</strong> A posição de montagem deve ser escolhida conforme a forma de instalação do conjunto, o espaço disponível e as condições reais de uso do motor.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a identificar como o motor será montado na aplicação, contribuindo para uma instalação correta, segura e compatível com o projeto.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Posição de Montagem do Motor', imagemPadrao.fallbackSrc)
                    };
                    imagensPorOpcao: { }
                },

                GrupoMOPP() {
                    const imagemPadrao = obterImagemPadraoCampo('MOPP', 'Posição do Prensa Cabo do Motor');
                    return {
                        title: 'Posição do Prensa Cabo do Motor:',
                        text: `<p><strong>Define a posição do prensa-cabo no motor.</strong> Esse campo indica em qual orientação ficará a entrada dos cabos elétricos, ajudando na organização da instalação e no acesso à ligação elétrica.</p>` +
                            `<p><strong>O Que Observar:</strong> A posição do prensa-cabo deve ser escolhida conforme a necessidade de entrada dos cabos, a facilidade de montagem e o espaço disponível ao redor do motor.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a definir a melhor orientação para a conexão elétrica do motor, facilitando a instalação, a manutenção e o acabamento final do conjunto.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Posição do Prensa Cabo do Motor', imagemPadrao.fallbackSrc)
                    };
                    imagensPorOpcao: { }
                },

                GrupoMOOPC() {
                    const { opcoesHtml, imagensHtml, imagemPadrao } = montarBlocoOpcoes({
                        idCampo: 'MOOPC',
                        filtrarPorDisponibilidade: true,
                        opcoes: [
                            {
                                valor: 'ACOR',
                                titulo: 'Pintura Anticorrosiva',
                                descricao: 'Camada adicional de proteção superficial, indicada para ambientes agressivos, úmidos ou com presença de agentes corrosivos.',
                            },
                            {
                                valor: 'PINTCZ',
                                titulo: 'Pintura Cinza',
                                descricao: 'Acabamento com proteção superficial adicional, mantendo a proposta visual definida para a aplicação.',
                            },
                            {
                                valor: 'VF',
                                titulo: 'Ventilação Forçada',
                                descricao: 'Sistema de refrigeração independente da rotação do motor, indicado em aplicações com variação de velocidade ou baixa rotação.',
                            },
                            {
                                valor: 'CR',
                                titulo: 'Contra Recuo',
                                descricao: 'Dispositivo que impede o giro reverso do conjunto, usado quando o retorno indesejado pode comprometer a operação.',
                            },
                            {
                                valor: 'NAO',
                                titulo: 'Não',
                                descricao: 'Indica fornecimento do motor sem opcionais adicionais.',
                            },
                        ],
                        imagensPorOpcao: {},
                    });

                    return {
                        title: 'Itens Opcionais do Motor:',
                        text: `<p><strong>Define os itens extras que podem ser incluídos no motor.</strong> Esse campo indica recursos ou acessórios adicionais que podem ser aplicados conforme a necessidade da instalação, do ambiente de trabalho ou da aplicação desejada.</p>` +
                            `<p><strong>O Que Observar:</strong> Os itens opcionais não fazem parte da configuração padrão em todos os casos e devem ser escolhidos quando houver necessidade específica de proteção, refrigeração adicional ou segurança operacional.</p>` +
                            `<p><strong>Opcionais Disponíveis:</strong><br>` +
                            opcoesHtml +
                            `</p>` +
                            (imagensHtml || imagemFormatada(imagemPadrao.src, 'Itens Opcionais do Motor', imagemPadrao.fallbackSrc))
                    };
                },

                GrupoMOCR() {
                    const imagemPadrao = obterImagemPadraoCampo('MOCR', 'Sentido de Giro do Motor');
                    return {
                        title: 'Sentido de Giro do Motor:',
                        text: `<p><strong>Define o sentido de rotação permitido para o motor.</strong> Esse campo é usado principalmente quando o motor possui contra recuo, indicando em qual direção o conjunto deve operar corretamente.</p>` +
                            `<p><strong>O Que Observar:</strong> A definição do sentido de giro deve estar de acordo com a necessidade da aplicação, porque o contra recuo impede a rotação no sentido oposto. Uma escolha incorreta pode comprometer o funcionamento do equipamento.</p>` +
                            `<p><strong>Na Prática:</strong> Esse campo ajuda a garantir que o motor opere no sentido correto para a aplicação, assegurando compatibilidade com o sistema acionado e maior segurança no funcionamento.</p>` +
                            imagemFormatada(imagemPadrao.src, 'Sentido de Giro do Motor', imagemPadrao.fallbackSrc)
                    };
                },
            };
        },

        SeletoresConfiguradorPL() {
        },

        SeletoresConfiguradorQU() {
        },

        SeletoresConfiguradorQUDR() {
        },

        SeletoresConfiguradorConcorrentes() {
        },

        SeletoresConfiguradorVA() {
        },
    };

    function getPageHelpConfigs(pageId) {
        try {
            const factory = pageHelpFactories[pageId];
            return typeof factory === 'function' ? factory() : {};
        } catch (error) {
            return {};
        }
    }

    function getAvailablePages() {
        try {
            return Object.keys(pageHelpFactories);
        } catch (error) {
            return [];
        }
    }

    window.HelpContentCatalog = {
        HELP_CATALOG_VERSION,
        getPageHelpConfigs,
        getAvailablePages
    };
})(window);
