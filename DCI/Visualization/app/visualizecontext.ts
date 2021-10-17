import {DataInterfaceGetOptions, DataSet} from 'vis-data'
import {Network, IdType, Node, Edge, DirectionType} from 'vis-network'
import {Context, RefType, Method, Ref} from './context'

enum Clicks {
    Single = 1,
    Double,
    Triple
}

export type VisualizeContextState = {
    onlyInteractions: boolean,
    file: string
}

type RoleMapData = { 
    interfaces: {name: string, id: string}[]
    methods: {name: string, id: string}[]
}

class ContextToVis {
    public static readonly CONTEXT = '__CONTEXT'
    public static readonly ARRAY = '__ARRAY'

    constructor(context: Context) {
        this.roles = new Map(Object.entries(context.roles))
        this.methods = Object.values(context.methods)

        this.refs = this.methods
        .flatMap((m : Method) => m.refs)
        .filter(r => r.type != RefType.Property && r.type != RefType.RoleAssignment)
    }

    create() {
        return this.methods_addMethods()
    }

    static isInterface(id: string) {
        return id.endsWith('_RI')
    }

    ///////////////////////////////////////////////////////

    private roles : Map<string, {
        methods: { [name: string]: string }
    }>

    protected roles_methods(role: string) {
        return Object.entries(this.roles.get(role).methods)
    }

    private roles_nodesForArc(roleName: string, nodes: {name: string, id: string}[], from: number, to: number, radius: number, isInterface: boolean) {
        const offset = (to - from) / nodes.length

        return nodes.map((node, index) => {
            let label : string

            if(roleName == ContextToVis.CONTEXT) {
                // Context access
                label = node.name
            } else if(node.name == '__ARRAY') {
                // Role player array access
                label = roleName + "[]"
            } else if(roleName == node.name) {
                // Direct Role player access
                label = roleName
            } else {
                // RoleMethod access
                label = roleName + "\n" + node.name
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

    protected roles_createNodes(roleMap: Map<string, RoleMapData>) {
        const totalLength = Array.from(roleMap.values()).reduce((prev, curr) => {
            return prev + Math.max(curr.interfaces.length, curr.methods.length)
        }, 0)
        
        const nodes : Node[] = []

        let offset = (3/4) * 2 * Math.PI

        for(const [roleName, role] of roleMap) {
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

        for(const [roleName, role] of roleMap) {
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

        return this.methods_createEdges(nodes)
    }

    ///////////////////////////////////////////////////////

    private methods : Array<{
        fullName: string
        role?: string
        refs: Array<Ref>
    }>

    protected methods_addMethods() {
        const roleMap : Map<string, RoleMapData> = new Map([[
            ContextToVis.CONTEXT, {interfaces: [], methods: []}
        ]])

        this.methods.forEach(method => {
            if(method.role) {
                const methodInfo = this.roles_methods(method.role)
                .find(e => e[1] == method.fullName)

                if(!roleMap.has(method.role))
                    roleMap.set(method.role, {interfaces: [], methods: []})
                
                roleMap.get(method.role).methods.push({name: methodInfo[0], id: methodInfo[1]})
            }
            else if(method.refs.find(r => r.type != RefType.Property && r.type != RefType.RoleAssignment)) {
                roleMap.get(ContextToVis.CONTEXT).methods.push({name: method.fullName, id: method.fullName})
            }
        })

        return this.refs_addRoleInterfaces(roleMap)
    }

    protected methods_createEdges(nodes: Node[]) {
        return {
            nodes,
            edges: this.methods.flatMap(m => m.refs
            .filter(r => 
                r.type != RefType.Property && 
                r.type != RefType.RoleAssignment &&
                (r.type != RefType.Role || r.contractCall)
            )
            .map(ref => ({
                from: m.fullName,
                to: ref.type == RefType.Role
                    ? this.refs_roleInterfaceId(ref)
                    : ref.to
            })))
        }        
    }

    ///////////////////////////////////////////////////////

    private refs : Array<{
        to: string,
        type: RefType
        contractCall?: string
    }>

    protected refs_addRoleInterfaces(roleMap: Map<string, RoleMapData>) {
        this.refs
        .filter(ref => ref.type == RefType.Role && 
            ref.contractCall && ref.contractCall != '__ARRAY'
        )
        .forEach(ref => {
            const id = this.refs_roleInterfaceId(ref)
            const interfaces = roleMap.get(ref.to).interfaces

            if(!interfaces.find(i => i.id == id)) {
                interfaces.push({
                    name: ref.contractCall ? ref.contractCall : ref.to, 
                    id
                })
            }
        })

        return this.roles_createNodes(roleMap)
    }

    protected refs_roleInterfaceId(ref) {
        return ref.to + (ref.contractCall ? '_' + ref.contractCall : '') + '_RI'
    }
}

/////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////

export class VisualizeContext {
    constructor(context: Context, container: HTMLElement, initialState?: VisualizeContextState) {
        let {nodes, edges} = new ContextToVis(context).create()

        //this.roles = new Set(nodes.map(node => node.group))

        console.log(edges)

        const nodeSet = this.nodes = new DataSet<Node>(nodes)
        const edgeSet = this.edges = new DataSet<Edge>(edges)

        // Set node and edge properties based on connected edges        
        nodeSet.update(nodeSet.get()
        .map(node => {
            const nodeEdgesFrom = edgeSet.get({
                filter: e => e.from == node.id && !ContextToVis.isInterface(e.to.toString())
            })
            const nodeEdgesTo = edgeSet.get({filter: e => e.to == node.id})

            const uniqueEdges = (edges) => new Set(edges.map(e => e.from + e.to))

            const isInterface = ContextToVis.isInterface(node.id.toString())
            const isGetter = !isInterface && nodeEdgesFrom.length == 0

            // Nodes with no outgoing edges are "getters",
            // they are only used to access the RolePlayer, or for utility.
            if(nodeEdgesFrom.length == 0) {
                this._getterNodes.push(node.id)
                edgeSet.updateOnly(nodeEdgesTo.map(e => ({
                    id: e.id,
                    dashes: true,
                    width: 1
                })))
            }

            const borderWidth = uniqueEdges(nodeEdgesTo).size * 1.5

            let shape = null
            if(node.group != '__CONTEXT') {
                if(isInterface) shape = 'triangleDown'
                else shape = nodeEdgesFrom.length > 0 
                    ? 'dot' 
                    : 'diamond'
            }

            return {
                id: node.id,
                shape,
                borderWidth: borderWidth,   
                borderWidthSelected: borderWidth,
                size: 20 + nodeEdgesFrom.length * 3,
                opacity: isGetter && nodeEdgesTo.length == 1 ? 0.5 : undefined
            }
        }))

        const networkOptions = {
            physics: false,
            nodes: {
                shape: 'dot',
                font: {
                    size: 16,
                    multi: true
                }
            },
            edges: {
                arrows: 'to',
                width: 2,
                selectionWidth: width => Math.max(3, width * 1.5)
            },
            groups: {
                '__CONTEXT': {
                    shape: 'box',
                    shapeProperties: {
                        borderRadius: 1
                    },
                    color: '#bbb',
                    font: {
                        size: 18
                    }
                }
            }
        }

        this.network = new Network(container, {
            nodes: nodeSet,
            edges: edgeSet
        }, networkOptions as any)

        this._state = Object.assign({
            onlyInteractions: false
        }, initialState || {})

        this.clicks = [0, 0]
        
    } // end constructor

    
    ///// System operations /////////////////////////////////////////
    
    start() {
        const network = this.network as Network
        network.on("click", () => {
            this.network_displaySelection(this.clicks_track())
        })
        this.redraw()
    }

    setState(state: VisualizeContextState) {
        this._state = state
        this.redraw()
    }

    redraw() {
        this.network_displaySelection(Clicks.Single)
    }

    ///// State /////////////////////////////////////////////////////
    
    private _getterNodes : IdType[] = []

    private _state : {
        onlyInteractions: boolean
    }

    ///// Roles /////////////////////////////////////////////////////

    ///// edges ///////////////////////////////////////////

    private edges: {
        get() : Edge[]
        get(id: IdType): Edge | null;
        get(ids: IdType[]): Edge[]
        get(options?: DataInterfaceGetOptions<Edge>): Edge[]
        update(data: { id: IdType; hidden: boolean }[], senderId?: undefined)
    }

    protected edges_displayAll() : void {
        this.edges_display(null)
    }

    protected edges_hideAll() : void {
        this.edges_display(null, false)
    }

    protected edges_display(edgeIds?: IdType[], display = true) : void {
        if(edgeIds == null)
            edgeIds = this.edges.get().map(e => e.id)

        let updates = edgeIds.map(id => ({
            id: id,
            hidden: !display
        }))
        
        if(display && this._state.onlyInteractions) {
            // Only display edges for nodes that are selected
            // or isn't a getter node.
            const selection = this.network_selectedNodes()

            updates.forEach(u => {
                const edge = this.edges.get(u.id)

                if(!selection.includes(edge.to) &&
                    !selection.includes(edge.from) &&
                    this._getterNodes.includes(edge.to)
                ) {
                    u.hidden = true
                }
            })
        }

        this.edges.update(updates)
    }

    ///// clicks //////////////////////////////////////////

    private clicks: [number, number];

    protected clicks_track() : Clicks {
        const now = Date.now()

        let nrClicks = Clicks.Single
        if(now - this.clicks[1] < 600) nrClicks = Clicks.Triple
        else if(now - this.clicks[0] < 500) nrClicks = Clicks.Double

        this.clicks.unshift(now)
        this.clicks.pop()

        return nrClicks
    }

    ///// network /////////////////////////////////////////

    private network: {
        getConnectedNodes(nodeOrEdgeId: IdType, direction?: DirectionType): IdType[] | Array<{ fromId: IdType, toId: IdType }>;
        getConnectedEdges(nodeId: IdType): IdType[];
        getSelection(): { nodes: IdType[], edges: IdType[] };
    }

    protected network_selectedNodes() {
        return this.network.getSelection().nodes
    }

    protected network_displaySelection(modifier : Clicks) {
        const selected = this.network.getSelection()

        if(selected.nodes.length == 0 && selected.edges.length == 0) {
            this.edges_displayAll()
            return
        }

        // Hide all edges before displaying the selected ones
        this.edges_hideAll()

        const onlyExactNodes = modifier == Clicks.Single

        if(selected.nodes.length > 0 && modifier == Clicks.Triple) {
            this.edges_display(
                selected.nodes.flatMap(
                    nodeId => this.nodes_uniPathFrom(nodeId)
                )
            )
        } else if(selected.nodes.length > 0) {
            // Displaying nodes takes precedence above edges
            this.nodes_displayEdgesFor(selected.nodes, onlyExactNodes)
        } else {
            this.nodes_displayEdgesFor(
                selected.edges.flatMap(edgeId =>
                    this.network.getConnectedNodes(edgeId) as IdType[]
                ),
                onlyExactNodes
            )
        }
    }

    protected network_connectedEdges(nodeId: IdType) : IdType[] {
        return this.network.getConnectedEdges(nodeId)
    }

    ///// nodes ///////////////////////////////////////////

    private nodes: {
        get(id: IdType): Node | null;
        get(ids: IdType[]): Node[];
        get(options?: DataInterfaceGetOptions<Node>): Node[];
        //update(data: Node | Node[], senderId?: IdType): IdType[];
    }

    protected nodes_get(id: IdType) : Node {
        return this.nodes.get(id)
    }

    protected nodes_displayEdgesFor(nodeIdList: IdType[], onlyExactNodes: boolean) : void {
        const nodes = this.nodes.get(nodeIdList)

        const filter = onlyExactNodes
            ? n => nodes.some(n2 => n2.id == n.id)
            : n => nodes.some(selected => selected.group == n.group)

        const edges = this.nodes
        .get({ filter: filter })
        .map(n => n.id)
        .flatMap(id => this.network_connectedEdges(id))

        this.edges_display(edges)
    }

    protected nodes_uniPathFrom(nodeId: IdType, visitedIds: IdType[] = []) : IdType[] {
        visitedIds.push(nodeId)

        const fromEdges = (nodeId: IdType) => this.edges
        .get(this.network_connectedEdges(nodeId))
        .filter(e => e.from == nodeId)

        const allEdges = fromEdges(nodeId)

        const addEdges = allEdges
        .filter(e => fromEdges(e.to).length > 0)

        return addEdges.map(e => e.id).concat(
            allEdges
            .filter(e => !visitedIds.includes(e.to))
            .flatMap(e => this.nodes_uniPathFrom(e.to, visitedIds))
        )
    }
}
