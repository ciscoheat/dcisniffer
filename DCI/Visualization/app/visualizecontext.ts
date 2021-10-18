import {DataInterfaceGetOptions, DataSet} from 'vis-data'
import {Network, IdType, Node, Edge, DirectionType} from 'vis-network'
import {ContextToVis} from './contexttovis'

enum Clicks {
    Single = 1,
    Double,
    Triple
}

export type VisualizeContextState = {
    onlyInteractions: boolean,
    file: string
}

export class VisualizeContext {
    constructor(nodes: Node[], edges: Edge[], container: HTMLElement, initialState?: VisualizeContextState) {
        //this.roles = new Set(nodes.map(node => node.group))

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
                size: isInterface 
                    ? 12 + nodeEdgesTo.length * 3
                    : 20 + nodeEdgesFrom.length * 3,
                opacity: isGetter && nodeEdgesTo.length == 1 ? 0.5 : undefined,
                font: isInterface 
                    ? {color: '#555', size: 15} 
                    : undefined
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
