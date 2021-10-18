import {IdType, Node, Edge, DirectionType} from 'vis-network'
import {Context, RefType, Method, Ref, Access} from './context'

type RoleData = {name: string, id: string, access: Access}

type RoleMap = Map<string, { 
    interfaces: RoleData[]
    methods: RoleData[]
}>

export class ContextToVis {
    public static readonly CONTEXT = '__CONTEXT'
    public static readonly ARRAY = '__ARRAY'

    constructor(context: Context) {
        this.roles = new Map(Object.entries(context.roles))
        this.methods = Object.values(context.methods)

        this.refs = this.methods
        .flatMap((m : Method) => m.refs)
        .filter(r => r.type != RefType.Property && r.type != RefType.RoleAssignment)

        this.roleMap = new Map([[
            ContextToVis.CONTEXT, {interfaces: [], methods: []}
        ]])

        for (const role of this.roles.keys()) {
            this.roleMap.set(role, {interfaces: [], methods: []})
        }
    }

    create() {
        return this.methods_addMethods()
    }

    static isInterface(id: string) {
        return id.endsWith('_RI')
    }

    ///////////////////////////////////////////////////////

    private roleMap : RoleMap

    private roleMap_length() {
        return Array.from(this.roleMap.values()).reduce((prev, curr) => {
            return prev + Math.max(curr.interfaces.length, curr.methods.length)
        }, 0)
    }

    protected roleMap_createNodesAndEdges() {
        const totalLength = this.roleMap_length()        
        const nodes : Node[] = []

        let offset = (3/4) * 2 * Math.PI

        for(const [roleName, role] of this.roleMap) {
            const arcLength = Math.max(role.interfaces.length, role.methods.length)
            const radius = 225 + totalLength * 10

            const start = offset
            const arc = 2 * Math.PI * (arcLength / totalLength)
            const end = offset + arc

            const adjust = role.methods.length >= role.interfaces.length
                ? 0
                : arc / (role.methods.length + 2)

            this.roles_nodesForArc(roleName, role.methods, start + adjust, end - adjust, radius, false)
            .forEach(n => nodes.push(n))

            offset = end
        }

        offset = (3/4) * 2 * Math.PI

        for(const [roleName, role] of this.roleMap) {
            const arcLength = Math.max(role.interfaces.length, role.methods.length)
            const radius = 285 + totalLength * 14

            const start = offset
            const arc = 2 * Math.PI * (arcLength / totalLength)
            const end = offset + arc

            const adjust = role.interfaces.length > role.methods.length
                ? 0
                : arc / (role.interfaces.length + 2)

            this.roles_nodesForArc(roleName, role.interfaces, start + adjust, end - adjust, radius, true)
            .forEach(n => nodes.push(n))

            offset = end
        }

        return {
            nodes, 
            edges: this.methods_createEdges()
        }
    }

    ///////////////////////////////////////////////////////

    private roles : Map<string, {
        methods: { [name: string]: string }
    }>

    protected roles_methods(role: string) {
        return Object.entries(this.roles.get(role).methods)
    }

    private roles_nodesForArc(roleName: string, nodes: {name: string, id: string, access: Access}[], from: number, to: number, radius: number, isInterface: boolean) {
        const offset = (to - from) / nodes.length

        return nodes.map((node, index) => {
            let label : string

            if(roleName == ContextToVis.CONTEXT) {
                // Context method access
                label = node.name
            } else if(node.name == '__ARRAY') {
                // Role player array access
                label = roleName + "[]"
            } else if(roleName == node.name) {
                // Direct Role player access
                label = roleName
            } else {
                // RoleMethod access
                const data = [roleName, node.name]

                label = node.access == Access.Private
                    ? '<i>' + data.join('</i>\n<i>') + '</i>'
                    : data.join('\n')
            }

            const angle = from + offset * index

            return {
                id: node.id,
                label,
                group: roleName,
                x: radius * Math.cos(angle),
                y: radius * Math.sin(angle)
            }
        })
    }

    ///////////////////////////////////////////////////////

    private methods : Array<{
        fullName: string
        role?: string
        refs: Array<Ref>
        access: Access
    }>

    protected methods_addMethods() {
        this.methods.forEach(method => {
            if(method.role) {
                const methodInfo = this.roles_methods(method.role)
                .find(e => e[1] == method.fullName)

                this.roleMap.get(method.role).methods.push({
                    name: methodInfo[0], id: methodInfo[1], access: method.access
                })
            }
            else if(method.refs.find(r => r.type != RefType.Property && r.type != RefType.RoleAssignment)) {
                this.roleMap.get(ContextToVis.CONTEXT).methods.push({
                    name: method.fullName, id: method.fullName, access: method.access
                })
            }
        })

        return this.refs_addRoleInterfaces()
    }

    protected methods_createEdges() {
        return this.methods
        .flatMap(m => m.refs.filter(r => 
                r.type != RefType.Property && 
                r.type != RefType.RoleAssignment &&
                (r.type != RefType.Role || r.contractCall)
            )
            .map(ref => ({
                from: m.fullName,
                to: ref.type == RefType.Role
                    ? this.refs_roleInterfaceId(ref)
                    : ref.to
            })
        ))
    }

    ///////////////////////////////////////////////////////

    private refs : Array<{
        to: string,
        type: RefType
        contractCall?: string
    }>

    protected refs_addRoleInterfaces() {
        this.refs
        .filter(ref => ref.type == RefType.Role && 
            ref.contractCall && ref.contractCall != '__ARRAY'
        )
        .forEach(ref => {
            const id = this.refs_roleInterfaceId(ref)
            const interfaces = this.roleMap.get(ref.to).interfaces

            if(!interfaces.find(i => i.id == id)) {
                interfaces.push({
                    name: ref.contractCall ? ref.contractCall : ref.to, 
                    id,
                    access: Access.Private
                })
            }
        })

        return this.roleMap_createNodesAndEdges()
    }

    protected refs_roleInterfaceId(ref) {
        return ref.to + (ref.contractCall ? '_' + ref.contractCall : '') + '_RI'
    }
}
