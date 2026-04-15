<?php

declare(strict_types=1);

class Constants
{
    public const CHUNK_MIN_SIZE = 16384;
    public const CHUNK_MAX_SIZE = 1048576;
    public const CHUNK_ALIGNMENT = 4096;

    public const SYNC_DELAY_MS = 2000;
    public const RATE_DELAY_MIN_MS = 1000;
    public const RATE_DELAY_MAX_MS = 30000;
    public const MAX_SLOTS = 10;
    public const DEFAULT_RATE_DELAY_MS = 3000;
    public const FULL_SLOT_DELAY_MS = 10000;

    public const DEFAULT_SERVER_URL = 'http://respaldosucursal.servicios.care';

    public const POLL_INTERVAL_SECONDS = 300;
    public const STABILIZE_DELAY_MS = 2000;
    public const FULL_CHECK_INTERVAL_SECONDS = 3600;

    public const FILES_TO_WATCH = [
        'AJTFLU.DBF',
        'ASISTE.DBF',
        'CAJAS.DBF',
        'CANCFDI.DBF',
        'CANCFDI.FPT',
        'CANOTA.DBF',
        'CANOTA.DBT',
        'CANOTA.FPT',
        'CANOTAEX.DBF',
        'CARPORT.DBF',
        'CARPORT.FPT',
        'CASEMANA.DBF',
        'CAT_NEG.DBF',
        'CATPROD3.DBF',
        'CAT_PROD.DBF',
        'CCOTIZA.DBF',
        'CENTER.DBF',
        'CFDREL.DBF',
        'CG3_VAEN.DBF',
        'CG3_VAPA.DBF',
        'CLIENTE.DBF',
        'COBRANZA.DBF',
        'COMPRAS.DBF',
        'CONCXC.DBF',
        'CONVTODO.DBF',
        'CPEDIDO.DBF',
        'CPENDIE.DBF',
        'CPXCORTE.DBF',
        'CRMC_OBS.DBF',
        'CUENGAS.DBF',
        'CUNOTA.DBF',
        'DD_CONTROL.DBF',
        'DD_DATOS.DBF',
        'DESEMANA.DBF',
        'ES_COBRO.DBF',
        'EYSIENC.DBF',
        'EYSIPAR.DBF',
        'FACCFD.DBF',
        'FACCFD.FPT',
        'FLUJO01.DBF',
        'FLUJORES.DBF',
        'HISTORIA.DBF',
        'INVFSIC.DBF',
        'MASTER.DBF',
        'M_CONF.DBF',
        'MINTINV.DBF',
        'MOVCXCD.DBF',
        'MOVSINV.DBF',
        'N_CONF.DBF',
        'NEGADOS.DBF',
        'NOESTA.DBF',
        'NOHAY.DBF',
        'N_RESP.DBF',
        'N_RESP.DBT',
        'N_RESP_M.DBF',
        'N_RESP_M.DBT',
        'OBSDOCS.DBF',
        'PAGDOCS.DBF',
        'PAGMULT.DBF',
        'PAGSPEI.DBF',
        'PARAMS.DBF',
        'PARTCOMP.DBF',
        'PARTCOT.DBF',
        'PARXCAR.DBF',
        'PARVALES.DBF',
        'PAVACL.DBF',
        'PCOTIZA.DBF',
        'PEDIDO.DBF',
        'PEDIDO1.DBF',
        'PEDIDO2.DBF',
        'PPEDIDO.DBF',
        'PPENDIE.DBF',
        'RESP_PIN.DBF',
        'R_BBVA.DBF',
        'R_KUSHKI.DBF',
        'SERCFD2.DBF',
        'STOCK.DBF',
        'SUCURCTAI.DBF',
        'TABLA004.DBF',
        'TABLA005.DBF',
        'TERCAJAS.DBF',
        'TLSERVI.DBF',
        'USUARIOS.DBF',
        'VACLI.DBF',
        'VALES.DBF',
        'VALPEN.DBF',
        'VCPENDI.DBF',
        'VENDEDOR.DBF',
        'VENTA.DBF',
        'VENTA.DBT',
        'VENTA.FPT',
        'VENTAPP.DBF',
        'VPPENDI.DBF',
        'XCORTE.DBF',
    ];

    public const EXCLUDED_DIRS_WINDOWS = [
        'windows',
        'program files',
        'program files (x86)',
        'recycler',
        '$recycle.bin',
        'system volume information',
        'documents and settings',
        'perflogs',
        'quickbck',
        '.ar_work',
    ];

    public const EXCLUDED_DIRS_LINUX = [
        'proc',
        'sys',
        'dev',
        'run',
        'snap',
        'boot',
        'lib',
        'lib64',
        'bin',
        'sbin',
        'usr',
        'etc',
        'var',
        'opt',
        'quickbck',
        '.ar_work',
    ];
}