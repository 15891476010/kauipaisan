#!/bin/bash

# 设置颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 项目根目录
ROOT_DIR="/zhang/kuaipaisan"
APPS_DIR="${ROOT_DIR}/apps"

# 所有可用的项目列表
ALL_PROJECTS=("admin-web" "agent-web" "user-h5" "user-pc")
SELECTED_PROJECTS=()

# 打印带颜色的信息
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_menu_title() {
    echo -e "${CYAN}=========================================${NC}"
    echo -e "${CYAN}  项目构建工具 v2.0${NC}"
    echo -e "${CYAN}=========================================${NC}"
}

# 选择要打包的项目（支持多选）
select_projects() {
    SELECTED_PROJECTS=()
    echo ""
    print_info "请选择要打包的项目（输入数字，多个用空格分隔，如：1 3 4）"
    echo ""
    
    local i=1
    for project in "${ALL_PROJECTS[@]}"; do
        echo "  ${i}. ${project}"
        ((i++))
    done
    echo "  a. 全部项目"
    echo "  q. 取消返回"
    echo ""
    echo -n "请选择: "
    read -r input
    
    if [[ "$input" == "q" || "$input" == "Q" ]]; then
        return 1
    fi
    
    if [[ "$input" == "a" || "$input" == "A" ]]; then
        SELECTED_PROJECTS=("${ALL_PROJECTS[@]}")
        print_info "已选择全部项目"
        return 0
    fi
    
    # 解析数字输入（支持空格分隔的多个数字）
    local selected=()
    local invalid=()
    for num in $input; do
        if [[ "$num" =~ ^[0-9]+$ ]] && [ "$num" -ge 1 ] && [ "$num" -le "${#ALL_PROJECTS[@]}" ]; then
            selected+=("${ALL_PROJECTS[$((num-1))]}")
        else
            invalid+=("$num")
        fi
    done
    
    if [ ${#invalid[@]} -gt 0 ]; then
        print_warning "无效数字: ${invalid[*]}，已忽略"
    fi
    
    if [ ${#selected[@]} -eq 0 ]; then
        print_warning "未选择任何有效项目"
        return 1
    fi
    
    SELECTED_PROJECTS=("${selected[@]}")
    echo ""
    print_info "已选择以下项目:"
    for project in "${SELECTED_PROJECTS[@]}"; do
        echo "  - ${project}"
    done
    echo ""
    echo -n "确认选择？[Y/n]: "
    read -r confirm
    if [[ "$confirm" =~ ^[Nn]$ ]]; then
        return 1
    fi
    
    return 0
}

# 显示主菜单
show_main_menu() {
    echo ""
    print_menu_title
    echo "  1. 先更新代码，再打包项目"
    echo "  2. 直接打包项目（不更新代码）"
    echo "  3. 只更新代码（不打包）"
    echo "  4. 退出"
    echo "========================================="
    echo -n "请选择 [1-4]: "
}

# 处理 Git 冲突
handle_conflicts() {
    local has_conflict=false
    
    if git status --porcelain | grep -q "^UU\|^AA\|^DD\|^AU\|^UA\|^DU\|^UD"; then
        has_conflict=true
    fi
    
    if [ -d "${ROOT_DIR}/.git/MERGE_HEAD" ] || [ -f "${ROOT_DIR}/.git/MERGE_HEAD" ]; then
        has_conflict=true
    fi
    
    if [ "$has_conflict" = true ]; then
        print_warning "检测到 Git 冲突！"
        echo ""
        echo "冲突文件列表："
        git status --porcelain | grep "^UU\|^AA\|^DD\|^AU\|^UA\|^DU\|^UD" | while read line; do
            echo "  - ${line}"
        done
        echo ""
        echo "请手动解决冲突后再继续..."
        echo ""
        echo "解决冲突后，请执行以下命令："
        echo "  git add ."
        echo "  git commit -m 'Merge resolved'"
        echo ""
        echo "然后重新运行此脚本。"
        exit 1
    fi
    
    return 0
}

# 更新代码
update_code() {
    print_info "切换到项目根目录: ${ROOT_DIR}"
    cd "${ROOT_DIR}" || exit 1
    
    if ! git diff --quiet || ! git diff --cached --quiet; then
        print_warning "检测到本地未提交的修改"
        echo -n "是否先暂存这些修改？[y/N]: "
        read -r answer
        if [[ "$answer" =~ ^[Yy]$ ]]; then
            print_info "暂存本地修改..."
            git stash push -m "自动暂存 - $(date '+%Y-%m-%d %H:%M:%S')"
        else
            print_warning "跳过暂存，继续拉取（可能会失败）"
        fi
    fi
    
    print_info "拉取远程代码..."
    if git pull origin main --no-rebase; then
        print_success "代码更新成功！"
        handle_conflicts
    else
        print_error "代码拉取失败！"
        handle_conflicts
        exit 1
    fi
    
    echo ""
    print_info "当前 Git 状态："
    git status --short
    echo ""
}

# 构建单个项目
build_project() {
    local project=$1
    local project_path="${APPS_DIR}/${project}"
    
    if [ ! -d "${project_path}" ]; then
        print_warning "项目目录不存在: ${project_path}，跳过"
        return 1
    fi
    
    print_info "构建项目: ${project}"
    cd "${project_path}" || return 1
    
    if [ ! -f "package.json" ]; then
        print_warning "项目 ${project} 没有 package.json，跳过"
        return 1
    fi
    
    if [ ! -d "node_modules" ]; then
        print_info "安装 ${project} 依赖..."
        npm install
        if [ $? -ne 0 ]; then
            print_error "${project} 依赖安装失败！"
            return 1
        fi
    fi
    
    print_info "执行 npm run build for ${project}..."
    if npm run build; then
        print_success "${project} 构建成功！"
        return 0
    else
        print_error "${project} 构建失败！"
        return 1
    fi
}

# 打包选中的项目
build_selected_projects() {
    if [ ${#SELECTED_PROJECTS[@]} -eq 0 ]; then
        print_warning "没有选择任何项目"
        return 1
    fi
    
    echo ""
    print_info "开始构建选中的项目..."
    echo "========================================="
    
    local failed_projects=()
    local success_count=0
    
    for project in "${SELECTED_PROJECTS[@]}"; do
        if build_project "${project}"; then
            ((success_count++))
        else
            failed_projects+=("${project}")
        fi
        echo "-----------------------------------------"
    done
    
    echo ""
    echo "========================================="
    print_info "构建完成！"
    echo "  成功: ${success_count}/${#SELECTED_PROJECTS[@]}"
    
    if [ ${#failed_projects[@]} -gt 0 ]; then
        print_error "失败的项目:"
        for project in "${failed_projects[@]}"; do
            echo "  - ${project}"
        done
        return 1
    else
        print_success "所有选中项目构建成功！"
        return 0
    fi
}

# 主函数
main() {
    cd "${ROOT_DIR}" || exit 1
    
    while true; do
        show_main_menu
        read -r main_choice
        
        case $main_choice in
            1)
                echo ""
                print_info "选择: 更新代码并打包"
                update_code
                
                # 选择要打包的项目
                if select_projects; then
                    build_selected_projects
                else
                    print_info "已取消打包"
                fi
                
                echo ""
                print_success "全部完成！"
                break
                ;;
            2)
                echo ""
                print_info "选择: 直接打包（不更新代码）"
                
                # 选择要打包的项目
                if select_projects; then
                    build_selected_projects
                else
                    print_info "已取消打包"
                fi
                
                echo ""
                print_success "全部完成！"
                break
                ;;
            3)
                echo ""
                print_info "选择: 只更新代码（不打包）"
                update_code
                echo ""
                print_success "代码更新完成！"
                break
                ;;
            4)
                echo ""
                print_info "退出"
                exit 0
                ;;
            *)
                print_error "无效选择，请输入 1、2、3 或 4"
                ;;
        esac
    done
}

# 执行主函数
main
