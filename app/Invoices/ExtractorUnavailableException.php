<?php

namespace App\Invoices;

use RuntimeException;

/**
 * O binario de extracao nao esta acessivel ao processo que atende a
 * requisicao. E uma falha de instalacao, nao um problema do PDF enviado.
 */
class ExtractorUnavailableException extends RuntimeException {}
