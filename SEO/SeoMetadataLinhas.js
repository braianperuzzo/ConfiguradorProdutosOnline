(function () {
    if (typeof window === 'undefined') {
        return;
    }

    var CANONICAL_ORIGIN = 'https://configurador.redutoresibr.com.br';
    var DEFAULT_SOCIAL_IMAGE = CANONICAL_ORIGIN + '/Imagens/LogotipoAplicativo512512.png';

    function buildLineMetadata(commercialName, benefit, canonicalPath, socialImage) {
        return {
            commercialName: commercialName,
            benefit: benefit,
            canonicalUrl: CANONICAL_ORIGIN + canonicalPath,
            socialImage: socialImage || DEFAULT_SOCIAL_IMAGE
        };
    }

    window.__SEO_HOME_METADATA__ = {
        title: 'Configurador de Produtos IBR',
        description: 'Ferramenta online para configuração de redutores, motorredutores e motores das diversas linhas IBR.',
        canonicalUrl: CANONICAL_ORIGIN + '/',
        socialImage: DEFAULT_SOCIAL_IMAGE
    };

    window.__SEO_LINE_METADATA__ = {
        '1.C': buildLineMetadata('IBR C', 'Linha compacta para aplicações industriais com excelente custo-benefício.', '/ConfiguradorIBRC'),
        '1.FFA': buildLineMetadata('IBR PFFA', 'Solução robusta para aplicações de precisão e alta confiabilidade.', '/ConfiguradorIBRPFFA'),
        '1.FKA': buildLineMetadata('IBR XFKA', 'Desempenho reforçado para demandas de torque e durabilidade.', '/ConfiguradorIBRXFKA'),
        '1.FR': buildLineMetadata('IBR CFR', 'Configuração versátil para diferentes cenários de transmissão.', '/ConfiguradorIBRCFR'),
        '1.H': buildLineMetadata('IBR H', 'Linha de alto rendimento para aplicações contínuas.', '/ConfiguradorIBRH'),
        '1.M': buildLineMetadata('IBR M', 'Solução modular com ampla faixa de aplicação.', '/ConfiguradorIBRM'),
        '1.P': buildLineMetadata('IBR P', 'Conjunto eficiente para demandas de produtividade e estabilidade.', '/ConfiguradorIBRP'),
        '1.Q': buildLineMetadata('IBR Q', 'Linha de redutores para operação confiável em múltiplos processos.', '/ConfiguradorIBRQ'),
        '1.QDR': buildLineMetadata('IBR QDR', 'Projeto dedicado para requisitos de alto desempenho mecânico.', '/ConfiguradorIBRQDR'),
        '1.QP': buildLineMetadata('IBR QP', 'Linha otimizada para aplicações com exigência de robustez.', '/ConfiguradorIBRQP'),
        '1.R': buildLineMetadata('IBR R', 'Redutor versátil para diferentes faixas de carga.', '/ConfiguradorIBRR'),
        '1.V': buildLineMetadata('IBR V', 'Solução para controle eficiente de velocidade e torque.', '/ConfiguradorIBRV'),
        '1.VFN': buildLineMetadata('IBR VFN', 'Linha dedicada a aplicações com precisão operacional.', '/ConfiguradorIBRVFN'),
        '1.X': buildLineMetadata('IBR X', 'Linha de alto torque para operações severas.', '/ConfiguradorIBRX'),
        '1.Z': buildLineMetadata('IBR Z', 'Versão compacta para integração em espaços reduzidos.', '/ConfiguradorIBRZ'),
        '2.I': buildLineMetadata('IBR MS/ML', 'Motores e motorredutores com eficiência para múltiplas aplicações.', '/ConfiguradorIBRMSML'),
        '3.APM': buildLineMetadata('Anticorrosivos APM', 'Proteção avançada para ambientes agressivos.', '/ConfiguradorANTICORROSIVOSAPM'),
        '3.GR': buildLineMetadata('IBR GR', 'Linha orientada a performance e resistência em operação contínua.', '/ConfiguradorIBRGR'),
        '3.GS': buildLineMetadata('IBR GS', 'Solução eficiente para aplicações industriais diversas.', '/ConfiguradorIBRGS'),
        '3.I': buildLineMetadata('IBR T3A/T3C', 'Linha para aplicações especiais com elevada robustez.', '/ConfiguradorIBRT3AT3C'),
        '3.PB': buildLineMetadata('IBR PB', 'Linha com foco em confiabilidade e longa vida útil.', '/ConfiguradorIBRPB'),
        '3.PBL': buildLineMetadata('IBR PBL', 'Solução compacta com excelente desempenho mecânico.', '/ConfiguradorIBRPBL'),
        '3.RIC': buildLineMetadata('IBR RIC', 'Configuração dedicada para aplicações específicas de transmissão.', '/ConfiguradorIBRRIC'),
        '3.SA': buildLineMetadata('IBR SA', 'Linha robusta para ambientes industriais de alta exigência.', '/ConfiguradorIBRSA'),
        '3.SB': buildLineMetadata('IBR SB', 'Solução confiável para operação com alto índice de disponibilidade.', '/ConfiguradorIBRSB'),
        '3.SBL': buildLineMetadata('IBR SBL', 'Configuração otimizada para desempenho com compactação.', '/ConfiguradorIBRSBL'),
        '3.SD': buildLineMetadata('IBR SD', 'Linha para aplicações com controle e estabilidade de torque.', '/ConfiguradorIBRSD'),
        '3.SPM': buildLineMetadata('Anticorrosivos SPM', 'Proteção anticorrosiva para longa durabilidade dos conjuntos.', '/ConfiguradorANTICORROSIVOSSPM'),
        '3.W': buildLineMetadata('WEG Alto Rendimento', 'Motores de alto rendimento para maior eficiência energética.', '/ConfiguradorWEGALTORENDIMENTO'),
        '4.K': buildLineMetadata('IBR K', 'Linha de configuração versátil para múltiplas necessidades industriais.', '/ConfiguradorIBRK')
    };
})();
