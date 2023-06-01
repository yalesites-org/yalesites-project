#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
ENDCOLOR='\033[0m'

function _say() {
    local script_name="${0##*/}"
    echo -e "${GREEN}[$script_name] $1${ENDCOLOR}"
}

function _error() {
    local script_name="${0##*/}"
    echo -e "${RED}[$script_name] $1${ENDCOLOR}"
}

function _error_and_exit() {
    _error "$1"
    exit
}
