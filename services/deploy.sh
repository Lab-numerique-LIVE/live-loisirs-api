#!/usr/bin/env bash
# Deployment script for loisirs-live-api project
# François GUÉRIN <fguerin@ville-tourcoing.fr>
# 2018-05-31 09:00

########################################
# PATHS
########################################
export APACHE_AVAILABLE_PATH="/etc/apache2/sites-available"
export APACHE_ENABLED_PATH="/etc/apache2/sites-enabled"

export SERVICE_TARGET_PATH="/etc/systemd/system"
export SERVICE_CONF_TARGET_PATH="/etc/conf.d"

########################################
# COLORS
########################################
export RED='\033[1;31m'
export GREEN='\033[1;32m'
export YELLOW='\033[1;33m'
export BLUE='\033[1;34m'
export NC='\033[0m' # No Color
export OK="${GREEN}[ OK ]${NC}"

########################################
# LOGGING
########################################
export LEVEL_DEBUG=4
export LEVEL_INFO=3
export LEVEL_WARNING=2
export LEVEL_ERROR=1

export LOGLEVEL=${LEVEL_DEBUG}

function log_debug {
    test ${LOGLEVEL} -ge ${LEVEL_DEBUG} && echo -e "${GREEN}DEBUG${NC}::"$1
}

function log_info {
    test ${LOGLEVEL} -ge ${LEVEL_INFO} && echo -e "${BLUE}INFO${NC}::"$1
}

function log_warning {
    test ${LOGLEVEL} -ge ${LEVEL_WARNING} && echo -e "${YELLOW}WARNING${NC}::"$1
}

function log_error {
    test ${LOGLEVEL} -ge ${LEVEL_ERROR} && echo -e "${RED}ERROR${NC}::"$1
}

# PROJECT
PWD=$(pwd)

# FOLDERS
WWW_PROJECT_ROOT="/var/www/loisirs-live-api.tourcoing.fr"
NEEDED_FOLDERS="log log/php"
WEB_USER="loisirs-live"
WEB_GROUP="www-data"

# BUILD

# APACHE
APACHE_CONF="loisirs-live-api-tourcoing-fr.conf"
APACHE_AVAILABLE="${APACHE_AVAILABLE_PATH}/${APACHE_CONF}"
APACHE_ENABLED="${APACHE_ENABLED_PATH}/020-${APACHE_CONF}"

# Creates the needed folders
function deploy_folders () {
    log_info "Deploy folders"
    for folder in ${NEEDED_FOLDERS}; do
        if [[ ! -d "${WWW_PROJECT_ROOT}/${folder}" ]]; then
            mkdir "${WWW_PROJECT_ROOT}/${folder}"
            chown -R ${WEB_USER}:${WEB_GROUP} "${WWW_PROJECT_ROOT}/${folder}"
            log_info "Directory *"${WWW_PROJECT_ROOT}/${folder}"* created."
        fi
    done;
    log_info "Deploy folders ${OK}"
}

function deploy_build () {
    log_info "Deploy build"
    log_info "Deploy build ${OK}"
}

function deploy_apache() {
    log_info "Deploy apache"
    cp "./apache/${APACHE_CONF}" ${APACHE_AVAILABLE}
    ln -s "${APACHE_AVAILABLE}"  "${APACHE_ENABLED}"
    SETTINGS_OK=$(/usr/sbin/apache2ctl configtest);
    if [[ $? != 0 ]]; then
        log_error "Apache configtest has detected an configuration error: .conf file disabled"
        rm "${APACHE_ENABLED}"
        exit 1
    fi
    # Reload the apache server
    /bin/systemctl reload apache2.service
    log_info "Deploy apache${OK}"
}

# Services settings
SERVICE="_ALL_"
AVAILABLE_SERVICES="folders build apache"

# How to use the script !
function usage {
    echo "$0 deploy services scripts for inscription-intranet application"
    echo "Usage : $0 OPTIONS SERVICE_TYPE"
    echo "    Where OPTIONS are:"
    echo "        -h: Shows help message then exit"
    echo "        -d: debug: shows all messages"
    echo "        -s: service (default: _ALL_), available: ${SERVICE} ${AVAILABLE_SERVICES}"
    exit 0
}

# main function
function main {
    while getopts "hds:" opt; do
        case $opt in
            h)
                usage
            ;;
            d)
                export LOGLEVEL=3
            ;;
            s)
                SERVICE=${OPTARG}
            ;;
            \?)
                echo "ERROR: invalid argument !"
                usage
            ;;
        esac
    done
    
    if [[ "${SERVICE}" = "_ALL_"  ]]; then
        deploy_folders
        deploy_build
        deploy_apache
    else
        case ${SERVICE} in
            "folders")
                deploy_folders
                ;;
            "apache")
                deploy_apache
                ;;
            "build")
                deploy_build
                ;;
            *)
                usage
                ;;
        esac
    fi
}

# Main launcher
main
