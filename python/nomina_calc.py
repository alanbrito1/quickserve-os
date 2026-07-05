#!/usr/bin/env python3
"""
QuickServe OS v4.0 — Calculadora de Nómina y Carga Prestacional
=================================================================
Herramienta CLI para calcular el costo real de un empleado en Colombia
según la legislación laboral vigente.

USO:
    python nomina_calc.py                          # calcula con SMLMV 2026
    python nomina_calc.py --salario 2500000        # salario personalizado
    python nomina_calc.py --salario 1750905 --json # output JSON (para integración)
    python nomina_calc.py --batch empleados.json   # múltiples empleados desde archivo

FORMATO empleados.json:
    [
        {"nombre": "Ana García",  "salario": 1750905, "aux_transporte": true},
        {"nombre": "Luis Torres", "salario": 2500000, "aux_transporte": false}
    ]

RESULTADO ESPERADO con SMLMV 2026:
    Costo total empleador ≈ $2,907,969 COP/mes
"""

import json
import argparse
import sys
from typing import Optional

# ─── Parámetros por defecto (sincronizar con configuracion_negocio en DB) ───────
PARAMETROS_DEFAULT: dict = {
    "salario_minimo":             1_750_905.00,
    "aux_transporte":               249_095.00,
    # Cargas del empleador (%)
    "pct_salud_empleador":                8.50,
    "pct_pension_empleador":             12.00,
    "pct_arl":                            0.522,  # Riesgo Clase I (cocina/mostrador)
    "pct_caja_compensacion":              4.00,
    "pct_icbf":                           3.00,
    "pct_sena":                           2.00,
    # Provisiones mensuales (%)
    "pct_prima":                          8.33,
    "pct_cesantias":                      8.33,
    "pct_intereses_cesantias":            1.00,
    "pct_vacaciones":                     4.17,
}


def calcular_nomina(
    salario: float,
    aplica_aux: bool = True,
    params: Optional[dict] = None
) -> dict:
    """
    Calcula la nómina completa con carga prestacional para un empleado.

    Args:
        salario:    Salario base mensual en pesos colombianos
        aplica_aux: True si el empleado tiene derecho al auxilio de transporte
        params:     Parámetros de porcentajes (usa PARAMETROS_DEFAULT si None)

    Returns:
        dict con todos los componentes calculados y totales
    """
    p = params or PARAMETROS_DEFAULT

    aux = p["aux_transporte"] if aplica_aux else 0.0

    # ── Cargas del empleador ─────────────────────────────────────────────────
    salud    = round(salario * p["pct_salud_empleador"]    / 100, 2)
    pension  = round(salario * p["pct_pension_empleador"]  / 100, 2)
    arl      = round(salario * p["pct_arl"]                / 100, 2)
    caja     = round(salario * p["pct_caja_compensacion"]  / 100, 2)
    icbf     = round(salario * p["pct_icbf"]               / 100, 2)
    sena     = round(salario * p["pct_sena"]               / 100, 2)

    # ── Provisiones mensuales ────────────────────────────────────────────────
    prima    = round(salario * p["pct_prima"]                / 100, 2)
    ces      = round(salario * p["pct_cesantias"]            / 100, 2)
    int_ces  = round(salario * p["pct_intereses_cesantias"]  / 100, 2)
    vacac    = round(salario * p["pct_vacaciones"]           / 100, 2)

    total_cargas = round(salud + pension + arl + caja + icbf + sena, 2)
    total_prov   = round(prima + ces + int_ces + vacac, 2)
    costo_total  = round(salario + aux + total_cargas + total_prov, 2)

    return {
        "salario_base":            salario,
        "aux_transporte":          aux,
        "cargas": {
            "salud_empleador":     salud,
            "pension_empleador":   pension,
            "arl":                 arl,
            "caja_compensacion":   caja,
            "icbf":                icbf,
            "sena":                sena,
            "subtotal":            total_cargas,
        },
        "provisiones": {
            "prima":               prima,
            "cesantias":           ces,
            "intereses_cesantias": int_ces,
            "vacaciones":          vacac,
            "subtotal":            total_prov,
        },
        "total_cargas":            total_cargas,
        "total_provisiones":       total_prov,
        "costo_total_empleador":   costo_total,
    }


def fmt_cop(n: float) -> str:
    """Formatea un número como pesos colombianos."""
    return f"${n:>14,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def imprimir_liquidacion(nombre: str, resultado: dict) -> None:
    """Imprime el desglose completo de la liquidación en formato tabla."""
    sep  = "─" * 52
    sep2 = "═" * 52

    print(f"\n{sep2}")
    print(f"  LIQUIDACIÓN: {nombre}")
    print(sep2)

    def fila(label, valor, indent=2):
        spaces = " " * indent
        print(f"{spaces}{label:<30}{fmt_cop(valor):>18}")

    fila("Salario Base",     resultado["salario_base"])
    fila("Auxilio Transporte", resultado["aux_transporte"])
    print(f"  {sep}")

    print("  ■ CARGAS DEL EMPLEADOR")
    c = resultado["cargas"]
    fila("  Salud (8.50%)",              c["salud_empleador"],   4)
    fila("  Pensión (12.00%)",           c["pension_empleador"], 4)
    fila("  ARL (0.522%)",               c["arl"],               4)
    fila("  Caja de Compensación (4%)",  c["caja_compensacion"], 4)
    fila("  ICBF (3.00%)",               c["icbf"],              4)
    fila("  SENA (2.00%)",               c["sena"],              4)
    fila("  SUBTOTAL CARGAS",            c["subtotal"],          2)
    print(f"  {sep}")

    print("  ■ PROVISIONES MENSUALES")
    pv = resultado["provisiones"]
    fila("  Prima (8.33%)",              pv["prima"],               4)
    fila("  Cesantías (8.33%)",          pv["cesantias"],           4)
    fila("  Intereses Ces. (1.00%)",     pv["intereses_cesantias"], 4)
    fila("  Vacaciones (4.17%)",         pv["vacaciones"],          4)
    fila("  SUBTOTAL PROVISIONES",       pv["subtotal"],            2)

    print(f"  {sep2}")
    pct_sobre_salario = (resultado["costo_total_empleador"] / resultado["salario_base"] - 1) * 100
    print(f"  {'COSTO TOTAL EMPLEADOR':<30}{fmt_cop(resultado['costo_total_empleador']):>18}")
    print(f"  {'(incremento sobre salario)':<30}{pct_sobre_salario:>17.1f}%")
    print(f"  {sep2}\n")


def main():
    parser = argparse.ArgumentParser(
        description="QuickServe OS — Calculadora de Nómina y Carga Prestacional"
    )
    parser.add_argument(
        "--salario", type=float,
        default=PARAMETROS_DEFAULT["salario_minimo"],
        help=f"Salario base en COP (default: SMLMV 2026 = {PARAMETROS_DEFAULT['salario_minimo']:,.0f})"
    )
    parser.add_argument(
        "--sin-aux", action="store_true",
        help="No aplicar auxilio de transporte"
    )
    parser.add_argument(
        "--json", action="store_true",
        help="Salida en formato JSON (para integración con otros sistemas)"
    )
    parser.add_argument(
        "--batch", type=str, metavar="ARCHIVO.json",
        help="Calcular múltiples empleados desde un archivo JSON"
    )
    parser.add_argument(
        "--nombre", type=str, default="Empleado",
        help="Nombre del empleado (solo para la salida formateada)"
    )

    args = parser.parse_args()

    # ── Modo batch ─────────────────────────────────────────────────────────
    if args.batch:
        try:
            with open(args.batch, encoding="utf-8") as f:
                empleados = json.load(f)
        except (FileNotFoundError, json.JSONDecodeError) as e:
            print(f"Error al leer {args.batch}: {e}", file=sys.stderr)
            sys.exit(1)

        resultados = []
        for emp in empleados:
            r = calcular_nomina(
                salario    = float(emp["salario"]),
                aplica_aux = emp.get("aux_transporte", True),
            )
            r["nombre"] = emp.get("nombre", "—")
            resultados.append(r)

        if args.json:
            print(json.dumps(resultados, ensure_ascii=False, indent=2))
        else:
            total_empresa = 0.0
            for r in resultados:
                imprimir_liquidacion(r["nombre"], r)
                total_empresa += r["costo_total_empleador"]
            print(f"\n{'═'*52}")
            print(f"  COSTO TOTAL NÓMINA ({len(resultados)} empleados)")
            print(f"  {fmt_cop(total_empresa)}")
            print(f"{'═'*52}\n")
        return

    # ── Modo individual ─────────────────────────────────────────────────────
    resultado = calcular_nomina(
        salario    = args.salario,
        aplica_aux = not args.sin_aux,
    )

    if args.json:
        resultado["nombre"] = args.nombre
        print(json.dumps(resultado, ensure_ascii=False, indent=2))
    else:
        imprimir_liquidacion(args.nombre, resultado)

        # Verificación contra el resultado esperado del spec
        if abs(args.salario - PARAMETROS_DEFAULT["salario_minimo"]) < 1:
            esperado = 2_912_578
            calculado = resultado["costo_total_empleador"]
            diff = abs(calculado - esperado)
            print(f"  Resultado spec: ${esperado:>14,.2f}")
            print(f"  Diferencia:     ${diff:>14,.2f}  "
                  f"({'✓ dentro del rango ~' if diff < 10_000 else '⚠ verificar parámetros'})\n")


if __name__ == "__main__":
    main()
