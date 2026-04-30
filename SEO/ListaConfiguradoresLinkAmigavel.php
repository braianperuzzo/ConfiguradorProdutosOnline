<?php
function codigo_amigavel(string $codigo): string {
    static $map = [
        '1.Q' => 'IBRQ',
        '1.QDR' => 'IBRQDR',
        '1.QP' => 'IBRQP',
        '1.C' => 'IBRC',
        '1.H' => 'IBRH',
        '1.M' => 'IBRM',
        '1.P' => 'IBRP',
        '1.R' => 'IBRR',
        '1.X' => 'IBRX',
        '1.FFA' => 'IBRPFFA',
        '1.FKA' => 'IBRXFKA',
        '1.FR' => 'IBRCFR',
        '2.I' => 'IBRMSML',
        '3.I' => 'IBRT3AT3C',
        '3.W' => 'WEGALTORENDIMENTO',
        '3.APM' => 'ANTICORROSIVOSAPM',
        '3.SPM' => 'ANTICORROSIVOSSPM',
        '3.PB' => 'IBRPB',
        '3.PBL' => 'IBRPBL',
        '3.SA' => 'IBRSA',
        '3.SB' => 'IBRSB',
        '3.SBL' => 'IBRSBL',
        '3.SD' => 'IBRSD',
        '1.V' => 'IBRV',
        '1.Z' => 'IBRZ',
        '1.VFN' => 'IBRVFN',
        '3.GR' => 'IBRGR',
        '3.GS' => 'IBRGS',
        '3.RIC' => 'IBRRIC',
        '4.K' => 'IBRK',
    ];

    return $map[$codigo] ?? $codigo;
}